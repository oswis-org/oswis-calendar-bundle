<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\EventEditType;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * P2.7 — Event web admin CRUD (edit, soft-delete, restore).
 *
 * Create flow stays in the dedicated YearCloneController for now (it covers
 * the actual production "vytvořit nový ročník" workflow); a generic
 * "create blank event" form is intentionally out of scope.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminEventController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $eventRepository,
    ) {
    }

    public function edit(Request $request, string $eventSlug): Response
    {
        $event = $this->loadEvent($eventSlug);
        $form = $this->createForm(EventEditType::class, $event);
        $form->get('startDate')->setData($event->getStartDateTime());
        $form->get('endDate')->setData($event->getEndDateTime());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $start = $form->get('startDate')->getData();
            $end = $form->get('endDate')->getData();
            if ($start instanceof DateTime) {
                $event->setStartDateTime($start);
            }
            if ($end instanceof DateTime) {
                $event->setEndDateTime($end);
            }
            $this->em->persist($event);
            $this->em->flush();

            $this->addFlash('success', sprintf('Událost „%s" uložena.', $event->getName() ?? ''));

            return new RedirectResponse($this->generateUrl(
                'oswis_org_oswis_calendar_web_admin_event',
                ['eventSlug' => $event->getSlug()],
            ));
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/event_edit.html.twig', [
            'event'     => $event,
            'form'      => $form,
            'pageTitle' => sprintf('Upravit událost: %s', $event->getName() ?? ''),
            'page_title' => sprintf('Upravit událost: %s :: ADMIN', $event->getName() ?? ''),
        ]);
    }

    public function delete(Request $request, string $eventSlug): Response
    {
        $event = $this->loadEvent($eventSlug);
        if (!$this->isCsrfTokenValid('event_delete_'.$event->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        if (!$event->isDeleted()) {
            $event->setDeletedAt(new DateTime());
            $this->em->persist($event);
            $this->em->flush();
            $this->addFlash('warning', sprintf('Událost „%s" označena jako smazaná.', $event->getName() ?? ''));
        } else {
            $this->addFlash('info', 'Událost už byla smazaná.');
        }

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_event',
            ['eventSlug' => $event->getSlug()],
        ));
    }

    public function restore(Request $request, string $eventSlug): Response
    {
        $event = $this->loadEvent($eventSlug);
        if (!$this->isCsrfTokenValid('event_restore_'.$event->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        if ($event->isDeleted()) {
            $event->setDeletedAt(null);
            $this->em->persist($event);
            $this->em->flush();
            $this->addFlash('success', sprintf('Událost „%s" obnovena.', $event->getName() ?? ''));
        } else {
            $this->addFlash('info', 'Událost není smazaná.');
        }

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_event',
            ['eventSlug' => $event->getSlug()],
        ));
    }

    private function loadEvent(string $eventSlug): Event
    {
        return $this->eventRepository->getEvent([
            EventRepository::CRITERIA_SLUG            => $eventSlug,
            EventRepository::CRITERIA_INCLUDE_DELETED => true,
        ]) ?? throw $this->createNotFoundException(sprintf('Událost „%s" nenalezena.', $eventSlug));
    }
}
