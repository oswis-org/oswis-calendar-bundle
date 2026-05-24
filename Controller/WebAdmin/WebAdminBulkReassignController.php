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
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
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

        $participants = $sourceEvent
            ? iterator_to_array($this->participantService->getParticipants([
                ParticipantRepository::CRITERIA_EVENT                 => $sourceEvent,
                ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY  => $sourceCategory,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED       => false,
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
            ]))
            : [];

        if ($request->isMethod('POST') && 'execute' === $request->request->get('_action')) {
            if (!$this->isCsrfTokenValid('bulk_reassign', (string) $token)) {
                throw $this->createAccessDeniedException('Neplatný CSRF token.');
            }
            $targetOffer = $targetOfferId
                ? $this->em->getRepository(RegistrationOffer::class)->find((int) $targetOfferId)
                : null;
            if (!$targetOffer instanceof RegistrationOffer) {
                $this->addFlash('error', 'Nebyla vybrána cílová přihláška.');

                return $this->renderWizard($events, $categories, $offers, $participants, $sourceEvent, $sourceCategory);
            }
            $ids = array_filter(array_map('intval', is_array($participantIds) ? $participantIds : []));
            if (count($ids) === 0) {
                $this->addFlash('error', 'Nebyl označen žádný účastník.');

                return $this->renderWizard($events, $categories, $offers, $participants, $sourceEvent, $sourceCategory);
            }
            if (count($ids) > self::HARD_CAP) {
                $this->addFlash('error', sprintf(
                    'Vybráno %d účastníků — limit je %d. Rozděl do menších dávek.',
                    count($ids), self::HARD_CAP,
                ));

                return $this->renderWizard($events, $categories, $offers, $participants, $sourceEvent, $sourceCategory);
            }

            $moved = [];
            $failed = [];
            foreach ($ids as $id) {
                $p = $this->em->find(Participant::class, $id);
                if (!$p instanceof Participant) {
                    $failed[] = "#$id (nenalezen)";
                    continue;
                }
                // Scope guard: only allow moving participants that actually belong
                // to the sourceEvent the admin filtered on. Prevents DevTools form
                // injection of arbitrary participant IDs from unrelated events.
                if ($sourceEvent && $p->getEvent() !== $sourceEvent) {
                    $failed[] = sprintf('#%d (není ve zdrojové akci %s)', $id, $sourceEvent->getShortName() ?? $sourceEvent->getName() ?? '?');
                    continue;
                }
                try {
                    $p->setOffer($targetOffer);
                    $this->em->persist($p);
                    $moved[] = sprintf('#%d %s', $id, $p->getContact()?->getName() ?? '?');
                } catch (OswisException|\Throwable $e) {
                    $failed[] = sprintf('#%d (%s)', $id, $e->getMessage());
                }
            }
            $this->em->flush();

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

        return $this->renderWizard($events, $categories, $offers, $participants, $sourceEvent, $sourceCategory);
    }

    /**
     * @param list<\OswisOrg\OswisCalendarBundle\Entity\Event\Event> $events
     * @param list<\OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory> $categories
     * @param list<RegistrationOffer> $offers
     * @param list<Participant> $participants
     */
    private function renderWizard(
        array $events,
        array $categories,
        array $offers,
        array $participants,
        ?\OswisOrg\OswisCalendarBundle\Entity\Event\Event $sourceEvent,
        ?\OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory $sourceCategory,
    ): Response {
        return $this->render('@OswisOrgOswisCalendar/web_admin/bulk_reassign.html.twig', [
            'events'          => $events,
            'categories'      => $categories,
            'offers'          => $offers,
            'participants'    => $participants,
            'sourceEvent'     => $sourceEvent,
            'sourceCategory'  => $sourceCategory,
            'hardCap'         => self::HARD_CAP,
            'pageTitle'       => 'Hromadný přesun přihlášek',
            'page_title'      => 'Hromadný přesun přihlášek :: ADMIN',
        ]);
    }
}
