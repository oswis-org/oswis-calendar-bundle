<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Announcement\Announcement;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantGroup;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Push\PushSubscriptionRepository;
use OswisOrg\OswisCalendarBundle\Service\Push\PushNotificationService;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Nástěnka turnusu ve web adminu.
 *
 * PROČ to musí být tady: vzkazy pro účastníky šly do 27. 8. 2026 psát **jen z Ionicu**.
 * Během akce ale tým sedí u notebooku na recepci a delší text se na mobilu píše mizerně —
 * a právě po nástěnce jedou push notifikace, tedy jediná cesta, jak lidem něco říct hned.
 *
 * Odeslání pushe se spouští STEJNOU službou jako přes API ({@see PushNotificationService}),
 * takže se obě cesty chovají shodně: doručí se jen zveřejněný vzkaz s vyžádaným pushem,
 * a jen jednou.
 */
final class WebAdminAnnouncementController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $eventRepository,
        private readonly PushNotificationService $push,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[IsGranted('ROLE_MANAGER')]
    public function index(string $eventSlug): Response
    {
        $event = $this->resolveEvent($eventSlug);

        return $this->render('@OswisOrgOswisCalendar/web_admin/announcement/index.html.twig', [
            'event'      => $event,
            'eventSlug'  => $eventSlug,
            'vzkazy'     => $this->vzkazy($event),
            'pasky'      => $this->pasky($event),
            'odberatelu' => $this->pocetOdberatelu($event),
            'pageTitle'  => 'Nástěnka — '.($event->getName() ?? $eventSlug),
            'page_title' => 'Nástěnka :: ADMIN',
        ]);
    }

    #[IsGranted('ROLE_MANAGER')]
    public function save(Request $request, string $eventSlug): RedirectResponse
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'vzkaz_ulozit');

        $nadpis = trim((string) $request->request->get('name'));
        $text = trim((string) $request->request->get('textValue'));
        if ('' === $nadpis) {
            $this->addFlash('danger', 'Vzkaz musí mít nadpis.');

            return $this->zpet($eventSlug);
        }

        $id = $request->request->get('id');
        $vzkaz = is_numeric($id) ? $this->em->find(Announcement::class, (int) $id) : null;
        if (null !== $vzkaz && $vzkaz->getEvent()?->getId() !== $event->getId()) {
            $this->addFlash('danger', 'Ten vzkaz patří jinému turnusu.');

            return $this->zpet($eventSlug);
        }
        if (null === $vzkaz) {
            $vzkaz = new Announcement();
            $vzkaz->setEvent($event);
            $this->em->persist($vzkaz);
        }
        $vzkaz->setFieldsFromNameable(new Nameable($nadpis));
        $vzkaz->setTextValue('' === $text ? null : $text);
        $vzkaz->setTargetGroup($this->pasekZPozadavku($request, $event));
        $vzkaz->setPinnedUntil($this->casZPozadavku($request, 'pinnedUntil'));

        $zverejnit = '1' === $request->request->get('publish');
        if ($zverejnit && null === $vzkaz->getPublishedAt()) {
            $vzkaz->setPublishedAt(new DateTime());
        }
        if (!$zverejnit) {
            // Stažení z nástěnky. Push, který už odešel, se odvolat nedá — `pushSentAt`
            // proto zůstává, aby se při opětovném zveřejnění neposlal lidem podruhé.
            $vzkaz->setPublishedAt(null);
        }
        if ('1' === $request->request->get('push')) {
            $vzkaz->setPushRequested(true);
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('Vzkaz „%s" uložen.', $nadpis));
        $this->odesliPush($vzkaz);

        return $this->zpet($eventSlug);
    }

    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, string $eventSlug, int $announcementId): RedirectResponse
    {
        $event = $this->resolveEvent($eventSlug);
        $this->overToken($request, 'vzkaz_smazat_'.$announcementId);
        $vzkaz = $this->em->find(Announcement::class, $announcementId);
        if (null === $vzkaz || $vzkaz->getEvent()?->getId() !== $event->getId()) {
            $this->addFlash('danger', 'Vzkaz nenalezen.');

            return $this->zpet($eventSlug);
        }
        $vzkaz->setDeletedAt(new DateTime());
        $this->em->flush();
        $this->addFlash('success', 'Vzkaz stažen z nástěnky.');

        return $this->zpet($eventSlug);
    }

    /**
     * Odeslání pushe a ČESTNÉ hlášení výsledku.
     *
     * ⚠️ „Nespadlo to" není totéž co „došlo to". Když pro cíl není jediný odběr, služba
     * vzkaz označí za odeslaný (aby to cron nezkoušel donekonečna) — a tým by si bez tohohle
     * hlášení myslel, že notifikace dorazila. Stejná past jako u tichého selhání SMTP.
     */
    private function odesliPush(Announcement $vzkaz): void
    {
        if (!$vzkaz->isPushRequested() || null !== $vzkaz->getPushSentAt() || null === $vzkaz->getPublishedAt()) {
            return;
        }
        try {
            $vysledek = $this->push->odesliVzkaz($vzkaz);
        } catch (Throwable $e) {
            // Vzkaz na nástěnce je to podstatné; push je druhá cesta doručení.
            $this->logger->error('Vzkaz uložen, ale push se nepodařilo odeslat: '.$e->getMessage());
            $this->addFlash('danger', 'Vzkaz je na nástěnce, ale push se nepodařilo odeslat.');

            return;
        }
        if (true === $vysledek['skipped']) {
            $this->addFlash('warning', 'Push se neodeslal — nejsou nastavené VAPID klíče.');

            return;
        }
        if (0 === $vysledek['sent']) {
            $this->addFlash('warning', 'Vzkaz je na nástěnce, ale push NEDOSTAL NIKDO —'
                .' pro tenhle cíl není žádný odběr notifikací.');

            return;
        }
        $this->addFlash('success', sprintf('Push odeslán: %d lidem.', $vysledek['sent']));
    }

    private function pasekZPozadavku(Request $request, Event $event): ?ParticipantGroup
    {
        $id = $request->request->get('targetGroup');
        if (!is_numeric($id)) {
            return null;
        }
        $pasek = $this->em->find(ParticipantGroup::class, (int) $id);

        return $pasek?->getEvent()?->getId() === $event->getId() ? $pasek : null;
    }

    /**
     * ⚠️ Vrací `DateTime`, ne `DateTimeImmutable`. Settery entity berou `DateTimeInterface`,
     * takže neměnná varianta projde i PHPStanem — ale sloupce jsou typu `datetime` a Doctrine
     * na ní při ukládání spadne s 500 („Could not convert PHP value of type DateTimeImmutable").
     */
    private function casZPozadavku(Request $request, string $klic): ?DateTime
    {
        $hodnota = trim((string) $request->request->get($klic));
        if ('' === $hodnota) {
            return null;
        }
        try {
            return new DateTime($hodnota);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<Announcement>
     */
    private function vzkazy(Event $event): array
    {
        /** @var list<Announcement> $vzkazy */
        $vzkazy = $this->em->getRepository(Announcement::class)->createQueryBuilder('a')
            ->where('a.event = :e')->setParameter('e', $event)
            ->andWhere('a.deletedAt IS NULL')
            ->orderBy('a.pinnedUntil', 'DESC')->addOrderBy('a.publishedAt', 'DESC')->addOrderBy('a.id', 'DESC')
            ->getQuery()->getResult();

        return $vzkazy;
    }

    /**
     * @return list<ParticipantGroup>
     */
    private function pasky(Event $event): array
    {
        /** @var list<ParticipantGroup> $pasky */
        $pasky = $this->em->getRepository(ParticipantGroup::class)->createQueryBuilder('g')
            ->where('g.event = :e')->setParameter('e', $event)
            ->orderBy('g.mealOrder', 'ASC')->addOrderBy('g.name', 'ASC')
            ->getQuery()->getResult();

        return $pasky;
    }

    /**
     * Kolik lidí vůbec může push dostat. Bez tohohle čísla se dá vzkaz s pushem odeslat
     * s pocitem, že se něco stalo — a přitom nemá kdo poslouchat.
     *
     * Počítá se TOUŽ metodou, kterou používá odesílání (`proCil`), ne vlastním dotazem:
     * odběr visí na uživatelském účtu, ne na přihlášce, a cesta od účtu k turnusu vede
     * přes kontakt a účastnický kontakt. Vlastní dotaz by snadno ukázal jiné číslo,
     * než kolik lidí notifikaci opravdu dostane.
     */
    private function pocetOdberatelu(Event $event): int
    {
        $id = $event->getId();

        return null === $id ? 0 : \count($this->subscriptions->proCil($id));
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
            'oswis_org_oswis_calendar_web_admin_announcements',
            ['eventSlug' => $eventSlug],
        );
    }
}
