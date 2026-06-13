<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantCategoryRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * P2.4 — Bulk participant reassign wizard.
 *
 * Form: source event + source category → filtered list with checkboxes
 * + target RegistrationOffer (which ties event + category together).
 * Submit → for each selected participant, call setOffer(targetOffer).
 *
 * Safety: hard cap 100 participants per request, CSRF token, JS confirm
 * dialog, full flash log of who was moved + who failed.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminBulkReassignController extends AbstractController
{
    private const HARD_CAP = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantService $participantService,
        private readonly EventRepository $eventRepository,
        private readonly ParticipantCategoryRepository $categoryRepository,
    ) {
    }

    public function wizard(Request $request): Response
    {
        $sourceEventSlug = $request->query->get('sourceEventSlug');
        $sourceCategorySlug = $request->query->get('sourceCategorySlug');
        $targetOfferId = $request->request->get('targetOfferId');
        $participantIds = $request->request->all('participantIds');
        $token = $request->request->get('_token');

        $sourceEvent = $sourceEventSlug
            ? $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => (string) $sourceEventSlug])
            : null;
        $sourceCategory = $sourceCategorySlug
            ? $this->categoryRepository->findOneBy(['slug' => (string) $sourceCategorySlug])
            : null;

        $events = $this->eventRepository->findBy([], ['startDateTime' => 'DESC'], 30);
        $categories = $this->categoryRepository->findAll();
        $offers = $this->em->getRepository(RegistrationOffer::class)->findBy([], ['createdAt' => 'DESC'], 100);

        // "Přesunout" ze sjednoceného seznamu pošle vybraná ID jako preselectIds[] (bez zdrojového
        // rozsahu) → načteme přesně je a předzaškrtneme. Na re-render po neúspěšném execute použijeme
        // odeslané participantIds[], ať se výběr neztratí.
        $preselectIds = $this->intIds($request->request->all('preselectIds'));
        $displayIds = $preselectIds;
        if ([] === $displayIds && $request->isMethod('POST') && 'execute' === $request->request->get('_action')) {
            $displayIds = $this->intIds($participantIds);
        }
        $preselectMode = [] !== $displayIds && null === $sourceEvent;
        if ($preselectMode) {
            $participants = $this->participantService->getRepository()->findBy(['id' => $displayIds]);
        } elseif (null !== $sourceEvent) {
            $participants = iterator_to_array($this->participantService->getParticipants([
                ParticipantRepository::CRITERIA_EVENT                 => $sourceEvent,
                ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY  => $sourceCategory,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED       => false,
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
            ]), false);
        } else {
            $participants = [];
        }
        $preselectedIds = $preselectMode ? $displayIds : [];

        if ($request->isMethod('POST') && 'execute' === $request->request->get('_action')) {
            if (!$this->isCsrfTokenValid('bulk_reassign', (string) $token)) {
                throw $this->createAccessDeniedException('Neplatný CSRF token.');
            }
            $targetOffer = $targetOfferId
                ? $this->em->getRepository(RegistrationOffer::class)->find((int) $targetOfferId)
                : null;
            if (!$targetOffer instanceof RegistrationOffer) {
                $this->addFlash('error', 'Nebyla vybrána cílová přihláška.');

                return $this->renderWizard($events, $categories, $offers, $participants, $sourceEvent, $sourceCategory, $preselectMode, $preselectedIds);
            }
            $ids = $this->intIds($participantIds);
            if (count($ids) === 0) {
                $this->addFlash('error', 'Nebyl označen žádný účastník.');

                return $this->renderWizard($events, $categories, $offers, $participants, $sourceEvent, $sourceCategory, $preselectMode, $preselectedIds);
            }
            if (count($ids) > self::HARD_CAP) {
                $this->addFlash('error', sprintf(
                    'Vybráno %d účastníků — limit je %d. Rozděl do menších dávek.',
                    count($ids), self::HARD_CAP,
                ));

                return $this->renderWizard($events, $categories, $offers, $participants, $sourceEvent, $sourceCategory, $preselectMode, $preselectedIds);
            }

            $moved = [];
            $failed = [];
            /** @var list<array{participant: Participant, oldOffer: ?RegistrationOffer}> $movedSideEffects */
            $movedSideEffects = [];
            foreach ($ids as $id) {
                $p = $this->em->find(Participant::class, $id);
                if (!$p instanceof Participant) {
                    $failed[] = "#$id (nenalezen)";
                    continue;
                }
                // Konzistenční kontrola pro režim filtru podle akce: když admin filtroval podle
                // zdrojové akce, odmítni ID, která do ní nepatří (chytá zastaralý/upravený
                // formulář). NENÍ to bezpečnostní kontrola — ROLE_ADMIN smí legitimně přesunout
                // kohokoli; v preselect režimu ($sourceEvent === null) se záměrně přeskakuje,
                // protože přesun napříč akcemi je smyslem té vstupní cesty (jednotný seznam).
                if ($sourceEvent && $p->getEvent() !== $sourceEvent) {
                    $failed[] = sprintf('#%d (není ve zdrojové akci %s)', $id, $sourceEvent->getShortName() ?? $sourceEvent->getName() ?? '?');
                    continue;
                }
                $oldOffer = $p->getOffer();
                // Přeskoč no-op přesun (už je na cílové nabídce) — jinak by setOffer zbytečně
                // přepsal registraci a applyPostMoveSideEffects poslal mail + přepočítal kapacitu.
                if ($oldOffer === $targetOffer) {
                    $failed[] = sprintf('#%d (už je na cílové přihlášce)', $id);
                    continue;
                }
                try {
                    $p->setOffer($targetOffer);
                    $this->em->persist($p);
                    $moved[] = sprintf('#%d %s', $id, $p->getContact()?->getName() ?? '?');
                    $movedSideEffects[] = ['participant' => $p, 'oldOffer' => $oldOffer];
                } catch (\Throwable $e) {
                    $failed[] = sprintf('#%d (%s)', $id, $e->getMessage());
                }
            }
            $this->em->flush();

            // Po commitu: oznámení o změně přihlášky + přepočet obsazenosti zdrojové i cílové
            // nabídky. Až po flush — diff změn čte verzované záznamy zapsané teprve flushem.
            // applyPostMoveSideEffects chyby jen loguje, takže neúspěšný mail neshodí přesun.
            foreach ($movedSideEffects as $sideEffect) {
                $this->participantService->applyPostMoveSideEffects($sideEffect['participant'], $sideEffect['oldOffer']);
            }

            if (count($moved) > 0) {
                $this->addFlash('success', sprintf(
                    'Přesunuto %d účastníků na „%s": %s',
                    count($moved),
                    $targetOffer->getName() ?? '?',
                    implode(', ', $moved),
                ));
            }
            if (count($failed) > 0) {
                $this->addFlash('warning', sprintf(
                    'Nepřesunuto %d účastníků: %s',
                    count($failed),
                    implode(', ', $failed),
                ));
            }

            return new RedirectResponse($this->generateUrl(
                'oswis_org_oswis_calendar_web_admin_bulk_reassign',
                ['sourceEventSlug' => $sourceEventSlug, 'sourceCategorySlug' => $sourceCategorySlug],
            ));
        }

        return $this->renderWizard($events, $categories, $offers, $participants, $sourceEvent, $sourceCategory, $preselectMode, $preselectedIds);
    }

    /**
     * @param list<\OswisOrg\OswisCalendarBundle\Entity\Event\Event> $events
     * @param list<\OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory> $categories
     * @param list<RegistrationOffer> $offers
     * @param list<Participant> $participants
     * @param list<int> $preselectedIds
     */
    private function renderWizard(
        array $events,
        array $categories,
        array $offers,
        array $participants,
        ?\OswisOrg\OswisCalendarBundle\Entity\Event\Event $sourceEvent,
        ?\OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory $sourceCategory,
        bool $preselectMode = false,
        array $preselectedIds = [],
    ): Response {
        $distinctEvents = [];
        foreach ($participants as $listed) {
            $listedEvent = $listed->getEvent();
            if (null !== $listedEvent) {
                $distinctEvents[(string) $listedEvent->getId()] = true;
            }
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/bulk_reassign.html.twig', [
            'events'          => $events,
            'categories'      => $categories,
            'offers'          => $offers,
            'participants'    => $participants,
            'sourceEvent'     => $sourceEvent,
            'sourceCategory'  => $sourceCategory,
            'preselectMode'   => $preselectMode,
            'preselectedIds'  => $preselectedIds,
            'hardCap'         => self::HARD_CAP,
            'pageTitle'       => 'Hromadný přesun přihlášek',
            'page_title'      => 'Hromadný přesun přihlášek :: ADMIN',
            'distinctEventCount' => count($distinctEvents),
        ]);
    }

    /**
     * POST id pole → deduplikovaný seznam kladných intů.
     *
     * @param array<mixed> $raw
     *
     * @return list<int>
     */
    private function intIds(array $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $raw,
        ))));
    }
}
