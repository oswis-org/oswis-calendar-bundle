<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantGroup;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Utils\StringUtils;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pásky (skupiny účastníků) turnusu ve web adminu.
 *
 * PROČ to musí být tady: přiřadit účastníka do pásku uměl do 27. 8. 2026 **jen Ionic a jen po
 * jednom** (`participants.setGroup`), zakládat pásky taky jen Ionic. Rozdělit 551 přihlášených
 * by tedy znamenalo proklikat 551 obrazovek na mobilu. Na produkci proto byla ke 27. 8. **nula
 * pásků** — a s nimi nefungoval sloupec „Skupina / pásek" na check-inu, řazení „Dle pásku",
 * PDF seznamu dle pásku ani pořadí výdeje jídla.
 *
 * Celý modul je JEDNA stránka: pásky se zakládají, upravují i mažou nahoře a účastníci se
 * přiřazují dole jedním uložením. Rozdělení mezi obrazovky by u úkolu, který se dělá jednou
 * za ročník a najednou, jen přidávalo kroky.
 */
final class WebAdminParticipantGroupController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $eventRepository,
        private readonly ParticipantRepository $participantRepository,
    ) {
    }

    #[IsGranted('ROLE_MANAGER')]
    public function index(string $eventSlug): Response
    {
        $event = $this->resolveEvent($eventSlug);
        $pasky = $this->pasky($event);
        $ucastnici = $this->ucastnici($event);

        $pocty = [];
        $bezPasku = 0;
        foreach ($ucastnici as $ucastnik) {
            $idPasku = $ucastnik->getGroup()?->getId();
            if (null === $idPasku) {
                ++$bezPasku;
                continue;
            }
            $pocty[$idPasku] = ($pocty[$idPasku] ?? 0) + 1;
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/participant_group/index.html.twig', [
            'event'      => $event,
            'eventSlug'  => $eventSlug,
            'pasky'      => $pasky,
            'ucastnici'  => $ucastnici,
            'pocty'      => $pocty,
            'bezPasku'   => $bezPasku,
            'nahled'     => null,
            'pageTitle'  => 'Pásky — '.($event->getName() ?? $eventSlug),
            'page_title' => 'Pásky :: ADMIN',
        ]);
    }

    /**
     * Založení i úprava pásku jedním místem — `id` v požadavku rozhoduje, o co jde.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function save(Request $request, string $eventSlug): RedirectResponse
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'pasek_ulozit');

        $nazev = trim((string) $request->request->get('name'));
        if ('' === $nazev) {
            $this->addFlash('danger', 'Pásek musí mít název.');

            return $this->zpet($eventSlug);
        }
        $barva = trim((string) $request->request->get('color')) ?: null;
        $poradiRaw = trim((string) $request->request->get('mealOrder'));
        $poradi = '' === $poradiRaw ? null : (int) $poradiRaw;

        $id = $request->request->get('id');
        $pasek = is_numeric($id) ? $this->em->find(ParticipantGroup::class, (int) $id) : null;
        if (null !== $pasek && $pasek->getEvent()?->getId() !== $event->getId()) {
            // Pásek z jiného turnusu by se tudy dal přepsat, aniž by to bylo na stránce vidět.
            $this->addFlash('danger', 'Ten pásek patří jinému turnusu.');

            return $this->zpet($eventSlug);
        }
        if (null === $pasek) {
            $pasek = new ParticipantGroup();
            $pasek->setEvent($event);
            $this->em->persist($pasek);
        }
        $pasek->setFieldsFromNameable(new Nameable($nazev));
        $pasek->setColor($barva);
        $pasek->setMealOrder($poradi);
        $this->em->flush();
        $this->addFlash('success', sprintf('Pásek „%s" uložen.', $nazev));

        return $this->zpet($eventSlug);
    }

    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, string $eventSlug, int $groupId): RedirectResponse
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'pasek_smazat_'.$groupId);
        $pasek = $this->em->find(ParticipantGroup::class, $groupId);
        if (null === $pasek || $pasek->getEvent()?->getId() !== $event->getId()) {
            $this->addFlash('danger', 'Pásek nenalezen.');

            return $this->zpet($eventSlug);
        }

        // Smazat pásek, do kterého jsou lidé přiřazení, by je tiše nechalo bez pásku —
        // a na check-inu by se to projevilo až u stolu. Radši to odmítnout nahlas.
        $prirazenych = (int) $this->em->getRepository(Participant::class)
            ->createQueryBuilder('p')->select('COUNT(p.id)')
            ->where('p.group = :g')->andWhere('p.deletedAt IS NULL')
            ->setParameter('g', $pasek)->getQuery()->getSingleScalarResult();
        if ($prirazenych > 0) {
            $this->addFlash('danger', sprintf(
                'Pásek „%s" má přiřazených %d lidí. Nejdřív je přesuň jinam, pak ho jde smazat.',
                $pasek->getName() ?? '#'.$groupId,
                $prirazenych,
            ));

            return $this->zpet($eventSlug);
        }
        $this->em->remove($pasek);
        $this->em->flush();
        $this->addFlash('success', 'Pásek smazán.');

        return $this->zpet($eventSlug);
    }

    /**
     * Hromadné uložení přiřazení — celá tabulka najednou.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function assign(Request $request, string $eventSlug): RedirectResponse
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'pasky_prirazeni');

        /** @var array<array-key, mixed> $vyber */
        $vyber = $request->request->all('pasek');
        $pasky = [];
        foreach ($this->pasky($event) as $pasek) {
            if (null !== $pasek->getId()) {
                $pasky[$pasek->getId()] = $pasek;
            }
        }

        $zmeneno = 0;
        foreach ($this->ucastnici($event) as $ucastnik) {
            $id = $ucastnik->getId();
            if (null === $id || !array_key_exists($id, $vyber)) {
                continue;
            }
            $hodnota = $vyber[$id];
            $novy = is_numeric($hodnota) ? ($pasky[(int) $hodnota] ?? null) : null;
            if ($novy?->getId() === $ucastnik->getGroup()?->getId()) {
                continue;
            }
            $ucastnik->setGroup($novy);
            ++$zmeneno;
        }
        $this->em->flush();
        $this->addFlash('success', 0 === $zmeneno ? 'Nic se nezměnilo.' : "Přiřazení uloženo u {$zmeneno} lidí.");

        return $this->zpet($eventSlug);
    }

    /**
     * Rozdělení do pásků po řadě — a napřed jen NÁHLED.
     *
     * Rozděluje se DETERMINISTICKY podle ID, ne náhodně: náhled musí ukázat přesně to, co se
     * pak zapíše. Náhodné pořadí by při potvrzení vyšlo jinak a náhled by nebyl k ničemu.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function distribute(Request $request, string $eventSlug): Response
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'pasky_rozdelit');

        $pasky = $this->pasky($event);
        if ([] === $pasky) {
            $this->addFlash('danger', 'Nejdřív založ aspoň jeden pásek.');

            return $this->zpet($eventSlug);
        }
        $iVsechny = '1' === $request->request->get('vsechny');
        $ucastnici = $this->ucastnici($event);
        $kRozdeleni = $iVsechny
            ? $ucastnici
            : array_values(array_filter($ucastnici, static fn (Participant $p): bool => null === $p->getGroup()));

        if ([] === $kRozdeleni) {
            $this->addFlash('success', 'Všichni už pásek mají.');

            return $this->zpet($eventSlug);
        }

        $plan = [];
        foreach ($kRozdeleni as $poradi => $ucastnik) {
            $plan[] = ['ucastnik' => $ucastnik, 'pasek' => $pasky[$poradi % \count($pasky)]];
        }

        if ('1' !== $request->request->get('potvrdit')) {
            $souhrn = [];
            foreach ($plan as $radek) {
                $nazev = $radek['pasek']->getName() ?? '?';
                $souhrn[$nazev] = ($souhrn[$nazev] ?? 0) + 1;
            }

            return $this->render('@OswisOrgOswisCalendar/web_admin/participant_group/index.html.twig', [
                'event'      => $event,
                'eventSlug'  => $eventSlug,
                'pasky'      => $pasky,
                'ucastnici'  => $ucastnici,
                'pocty'      => $this->poctyPodlePasku($ucastnici),
                'bezPasku'   => \count(array_filter($ucastnici, static fn (Participant $p): bool => null === $p->getGroup())),
                'nahled'     => ['plan' => $plan, 'souhrn' => $souhrn, 'vsechny' => $iVsechny],
                'pageTitle'  => 'Pásky — '.($event->getName() ?? $eventSlug),
                'page_title' => 'Pásky :: ADMIN',
            ]);
        }

        foreach ($plan as $radek) {
            $radek['ucastnik']->setGroup($radek['pasek']);
        }
        $this->em->flush();
        $this->addFlash('success', sprintf('Rozděleno %d lidí do %d pásků.', \count($plan), \count($pasky)));

        return $this->zpet($eventSlug);
    }

    /**
     * @param list<Participant> $ucastnici
     *
     * @return array<int, int>
     */
    private function poctyPodlePasku(array $ucastnici): array
    {
        $pocty = [];
        foreach ($ucastnici as $ucastnik) {
            $id = $ucastnik->getGroup()?->getId();
            if (null !== $id) {
                $pocty[$id] = ($pocty[$id] ?? 0) + 1;
            }
        }

        return $pocty;
    }

    /**
     * @return list<ParticipantGroup>
     */
    private function pasky(Event $event): array
    {
        /** @var list<ParticipantGroup> $pasky */
        $pasky = $this->em->getRepository(ParticipantGroup::class)
            ->createQueryBuilder('g')
            ->where('g.event = :e')->setParameter('e', $event)
            ->orderBy('g.mealOrder', 'ASC')->addOrderBy('g.name', 'ASC')
            ->getQuery()->getResult();

        return $pasky;
    }

    /**
     * Účastníci turnusu — stejné vymezení jako check-in (jen TYPE_ATTENDEE), aby počty
     * na obou obrazovkách seděly.
     *
     * @return list<Participant>
     */
    private function ucastnici(Event $event): array
    {
        /** @var list<Participant> $ucastnici */
        $ucastnici = $this->participantRepository->getParticipants([
            ParticipantRepository::CRITERIA_EVENT                 => $event,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 0,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => ParticipantCategory::TYPE_ATTENDEE,
        ], true)->getValues();

        // Abecedně (česky) — v tabulce se tak člověk hledá, a rozdělení po řadě je díky tomu
        // pořád DETERMINISTICKÉ, takže náhled ukáže přesně to, co se pak zapíše.
        usort(
            $ucastnici,
            static fn (Participant $a, Participant $b): int => StringUtils::compareCzech(
                $a->getSortableName(),
                $b->getSortableName(),
            ),
        );

        return $ucastnici;
    }

    private function resolveEvent(string $eventSlug): Event
    {
        return $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug])
            ?? throw $this->createNotFoundException("Turnus '{$eventSlug}' nenalezen.");
    }

    private function overToken(Request $request, string $klic): void
    {
        if (!$this->isCsrfTokenValid($klic, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný token formuláře.');
        }
    }

    private function zpet(string $eventSlug): RedirectResponse
    {
        return $this->redirectToRoute(
            'oswis_org_oswis_calendar_web_admin_participant_groups',
            ['eventSlug' => $eventSlug],
        );
    }
}
