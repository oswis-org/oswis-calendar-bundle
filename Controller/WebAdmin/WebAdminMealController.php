<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Meal\Meal;
use OswisOrg\OswisCalendarBundle\Entity\Meal\MealVariant;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Jídelníček turnusu ve web adminu.
 *
 * PROČ to musí být tady: jídelníček žil jen v Ionicu, a na produkci proto bylo ke 27. 8. 2026
 * **nula jídel** — kuchyňský list tedy neměl co vytisknout. Zadat patnáct jídel se dvěma až
 * třemi variantami po jednom na mobilu nikdo dělat nebude.
 *
 * Součástí je **„Založit kostru jídel"**: snídaně, oběd a večeře na každý den turnusu jedním
 * kliknutím. Tým pak jen dopisuje varianty, místo aby zakládal patnáct prázdných jídel ručně.
 * Je to idempotentní — co už existuje, se nezaloží podruhé.
 */
final class WebAdminMealController extends AbstractController
{
    /** Kostra dne: typ jídla → výdej od–do. Vychází z běžného režimu turnusu. */
    private const array KOSTRA = [
        Meal::TYPE_BREAKFAST => ['07:30', '09:00'],
        Meal::TYPE_LUNCH     => ['12:00', '13:30'],
        Meal::TYPE_DINNER    => ['18:00', '19:30'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $eventRepository,
    ) {
    }

    #[IsGranted('ROLE_MANAGER')]
    public function index(string $eventSlug): Response
    {
        $event = $this->resolveEvent($eventSlug);

        return $this->render('@OswisOrgOswisCalendar/web_admin/meal/index.html.twig', [
            'event'      => $event,
            'eventSlug'  => $eventSlug,
            'dny'        => $this->jidlaPoDnech($event),
            'typy'       => $this->popisyTypu(),
            'kostraDnu'  => \count($this->dnyTurnusu($event)),
            'pageTitle'  => 'Jídelníček — '.($event->getName() ?? $eventSlug),
            'page_title' => 'Jídelníček :: ADMIN',
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    public function saveMeal(Request $request, string $eventSlug): RedirectResponse
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'jidlo_ulozit');

        $datum = $this->datumZPozadavku($request, 'date');
        $typ = (string) $request->request->get('type');
        if (null === $datum || !\in_array($typ, Meal::types(), true)) {
            $this->addFlash('danger', 'Jídlo musí mít platné datum a typ.');

            return $this->zpet($eventSlug);
        }

        $id = $request->request->get('id');
        $jidlo = is_numeric($id) ? $this->em->find(Meal::class, (int) $id) : null;
        if (null !== $jidlo && $jidlo->getEvent()?->getId() !== $event->getId()) {
            $this->addFlash('danger', 'To jídlo patří jinému turnusu.');

            return $this->zpet($eventSlug);
        }
        if (null === $jidlo) {
            $jidlo = new Meal();
            $jidlo->setEvent($event);
            $this->em->persist($jidlo);
        }
        $jidlo->setDate($datum);
        $jidlo->setType($typ);
        $jidlo->setServedFrom($this->casZPozadavku($request, 'servedFrom'));
        $jidlo->setServedTo($this->casZPozadavku($request, 'servedTo'));
        $nazev = trim((string) $request->request->get('name'));
        $jidlo->setFieldsFromNameable(new Nameable('' === $nazev ? null : $nazev));
        $this->em->flush();
        $this->addFlash('success', 'Jídlo uloženo.');

        return $this->zpet($eventSlug);
    }

    #[IsGranted('ROLE_ADMIN')]
    public function deleteMeal(Request $request, string $eventSlug, int $mealId): RedirectResponse
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'jidlo_smazat_'.$mealId);
        $jidlo = $this->em->find(Meal::class, $mealId);
        if (null === $jidlo || $jidlo->getEvent()?->getId() !== $event->getId()) {
            $this->addFlash('danger', 'Jídlo nenalezeno.');

            return $this->zpet($eventSlug);
        }
        $jidlo->setDeletedAt(new DateTime());
        $this->em->flush();
        $this->addFlash('success', 'Jídlo odebráno z jídelníčku.');

        return $this->zpet($eventSlug);
    }

    #[IsGranted('ROLE_ADMIN')]
    public function saveVariant(Request $request, string $eventSlug, int $mealId): RedirectResponse
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'varianta_ulozit_'.$mealId);
        $jidlo = $this->em->find(Meal::class, $mealId);
        if (null === $jidlo || $jidlo->getEvent()?->getId() !== $event->getId()) {
            $this->addFlash('danger', 'Jídlo nenalezeno.');

            return $this->zpet($eventSlug);
        }
        $nazev = trim((string) $request->request->get('name'));
        if ('' === $nazev) {
            $this->addFlash('danger', 'Varianta musí mít název.');

            return $this->zpet($eventSlug);
        }
        $varianta = new MealVariant(new Nameable($nazev), (int) $request->request->get('position', '0'));
        $varianta->setAllergens(trim((string) $request->request->get('allergens')) ?: null);
        $varianta->setMeatFree('1' === $request->request->get('meatFree'));
        $jidlo->addVariant($varianta);
        $this->em->persist($varianta);
        $this->em->flush();
        $this->addFlash('success', sprintf('Varianta „%s" přidána.', $nazev));

        return $this->zpet($eventSlug);
    }

    #[IsGranted('ROLE_ADMIN')]
    public function deleteVariant(Request $request, string $eventSlug, int $variantId): RedirectResponse
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'varianta_smazat_'.$variantId);
        $varianta = $this->em->find(MealVariant::class, $variantId);
        if (null === $varianta || $varianta->getMeal()?->getEvent()?->getId() !== $event->getId()) {
            $this->addFlash('danger', 'Varianta nenalezena.');

            return $this->zpet($eventSlug);
        }
        $varianta->setDeletedAt(new DateTime());
        $this->em->flush();
        $this->addFlash('success', 'Varianta odebrána.');

        return $this->zpet($eventSlug);
    }

    /**
     * Kostra jídel na celý turnus — idempotentní, co existuje, se nezaloží podruhé.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function seed(Request $request, string $eventSlug): RedirectResponse
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'jidla_kostra');

        $existujici = [];
        foreach ($this->jidla($event) as $jidlo) {
            $existujici[$jidlo->getDate()?->format('Y-m-d').'|'.$jidlo->getType()] = true;
        }

        $zalozeno = 0;
        foreach ($this->dnyTurnusu($event) as $den) {
            foreach (self::KOSTRA as $typ => [$od, $do]) {
                if (isset($existujici[$den->format('Y-m-d').'|'.$typ])) {
                    continue;
                }
                $jidlo = new Meal();
                $jidlo->setEvent($event);
                $jidlo->setDate(DateTime::createFromInterface($den));
                $jidlo->setType($typ);
                $jidlo->setServedFrom(new DateTime($od));
                $jidlo->setServedTo(new DateTime($do));
                $this->em->persist($jidlo);
                ++$zalozeno;
            }
        }
        $this->em->flush();
        $this->addFlash('success', 0 === $zalozeno
            ? 'Kostra už existuje, nic se nepřidalo.'
            : "Založeno {$zalozeno} jídel. Doplň k nim varianty.");

        return $this->zpet($eventSlug);
    }

    /**
     * Dny turnusu včetně dne příjezdu i odjezdu.
     *
     * @return list<DateTimeInterface>
     */
    private function dnyTurnusu(Event $event): array
    {
        $od = $event->getStartDateTime();
        $do = $event->getEndDateTime();
        if (null === $od || null === $do || $do < $od) {
            return [];
        }
        $dny = [];
        $den = DateTime::createFromInterface($od)->setTime(0, 0);
        $konec = DateTime::createFromInterface($do)->setTime(0, 0);
        while ($den <= $konec && \count($dny) < 40) {
            $dny[] = DateTime::createFromInterface($den);
            $den = $den->modify('+1 day');
        }

        return $dny;
    }

    /**
     * @return list<Meal>
     */
    private function jidla(Event $event): array
    {
        /** @var list<Meal> $jidla */
        $jidla = $this->em->getRepository(Meal::class)->createQueryBuilder('m')
            ->where('m.event = :e')->setParameter('e', $event)
            ->andWhere('m.deletedAt IS NULL')
            ->orderBy('m.date', 'ASC')
            ->getQuery()->getResult();

        return $jidla;
    }

    /**
     * Jídla seskupená po dnech a v rámci dne seřazená podle typu (snídaně → oběd → svačina →
     * večeře), ne podle času zadání — jinak by jídelníček skákal.
     *
     * @return array<string, array{datum: DateTimeInterface, jidla: list<Meal>}>
     */
    private function jidlaPoDnech(Event $event): array
    {
        $dny = [];
        foreach ($this->jidla($event) as $jidlo) {
            $datum = $jidlo->getDate();
            if (null === $datum) {
                continue;
            }
            $klic = $datum->format('Y-m-d');
            $dny[$klic] ??= ['datum' => $datum, 'jidla' => []];
            $dny[$klic]['jidla'][] = $jidlo;
        }
        ksort($dny);
        foreach ($dny as $klic => $den) {
            $jidla = $den['jidla'];
            usort($jidla, static fn (Meal $a, Meal $b): int => $a->getTypeOrder() <=> $b->getTypeOrder());
            $dny[$klic]['jidla'] = $jidla;
        }

        return $dny;
    }

    /**
     * @return array<string, string>
     */
    private function popisyTypu(): array
    {
        return [
            Meal::TYPE_BREAKFAST => 'Snídaně',
            Meal::TYPE_LUNCH     => 'Oběd',
            Meal::TYPE_SNACK     => 'Svačina',
            Meal::TYPE_DINNER    => 'Večeře',
        ];
    }

    private function datumZPozadavku(Request $request, string $klic): ?DateTime
    {
        $hodnota = trim((string) $request->request->get($klic));

        try {
            return '' === $hodnota ? null : new DateTime($hodnota);
        } catch (Throwable) {
            return null;
        }
    }

    private function casZPozadavku(Request $request, string $klic): ?DateTime
    {
        $hodnota = trim((string) $request->request->get($klic));

        try {
            return '' === $hodnota ? null : new DateTime($hodnota);
        } catch (Throwable) {
            return null;
        }
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
            'oswis_org_oswis_calendar_web_admin_meals',
            ['eventSlug' => $eventSlug],
        );
    }
}
