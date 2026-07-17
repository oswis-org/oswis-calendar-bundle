<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailCategory;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ParticipantMailCategoryEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ParticipantMailGroupEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\TwigTemplateEditType;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\MailPreviewService;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Web admin CRUD over the mail templating chain so admins don't have to
 * edit ParticipantMailGroup / ParticipantMailCategory / TwigTemplate rows
 * directly in the DB.
 *
 * Scope: index page + edit + create (optionally prefilled via ?from=<id> —
 * covers the yearly season setup: clone last year's groups/categories and
 * adjust the year). DELETE stays unexposed (rare enough for DB access).
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminMailConfigController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailPreviewService $mailPreview,
        private readonly ParticipantRepository $participantRepository,
    ) {
    }

    public function index(): Response
    {
        // ParticipantMailGroup::$type is derived from category->getType() — not
        // a column — so `findBy(orderBy: ['type'])` raises UnrecognizedField.
        // Order by id ASC for stable display; admin can switch to category-sort later.
        $groups = $this->em->getRepository(ParticipantMailGroup::class)
            ->findBy([], ['id' => 'ASC']);
        $categories = $this->em->getRepository(ParticipantMailCategory::class)
            ->findBy([], ['priority' => 'ASC', 'id' => 'ASC']);
        $templates = $this->em->getRepository(TwigTemplate::class)
            ->findBy([], ['id' => 'ASC']);

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/index.html.twig', [
            'groups'     => $groups,
            'categories' => $categories,
            'templates'  => $templates,
            'pageTitle'  => 'Konfigurace e-mailů',
            'page_title' => 'Konfigurace e-mailů :: ADMIN',
        ]);
    }

    public function editGroup(Request $request, int $id): Response
    {
        $group = $this->em->find(ParticipantMailGroup::class, $id)
            ?? throw $this->createNotFoundException('Mail group nenalezena.');
        $form = $this->createForm(ParticipantMailGroupEditType::class, $group);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($group);
            $this->em->flush();
            $this->addFlash('success', sprintf('Mail group „%s" uložena.', $group->getName() ?? '#'.$id));

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_mail_config'));
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'       => $form,
            'entity'     => $group,
            'kind'       => 'group',
            'pageTitle'  => sprintf('Mail group: %s', $group->getName() ?? '#'.$id),
            'page_title' => sprintf('Mail group: %s :: ADMIN', $group->getName() ?? '#'.$id),
        ]);
    }

    public function editCategory(Request $request, int $id): Response
    {
        $category = $this->em->find(ParticipantMailCategory::class, $id)
            ?? throw $this->createNotFoundException('Mail kategorie nenalezena.');
        $form = $this->createForm(ParticipantMailCategoryEditType::class, $category);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($category);
            $this->em->flush();
            $this->addFlash('success', sprintf('Mail kategorie „%s" uložena.', $category->getName() ?? '#'.$id));

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_mail_config'));
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'       => $form,
            'entity'     => $category,
            'kind'       => 'category',
            'pageTitle'  => sprintf('Mail kategorie: %s', $category->getName() ?? '#'.$id),
            'page_title' => sprintf('Mail kategorie: %s :: ADMIN', $category->getName() ?? '#'.$id),
        ]);
    }

    /**
     * Create a new ParticipantMailGroup, optionally prefilled from an existing one (?from=<id>).
     * The date window and automaticMailing flag are deliberately NOT copied: an empty window means
     * "always applies", so a freshly cloned group must start disabled and windowless — enabling it
     * is a separate, explicit decision after the window is set.
     */
    public function newGroup(Request $request): Response
    {
        $group = new ParticipantMailGroup();
        $fromId = $request->query->getInt('from');
        $from = $fromId > 0 ? $this->em->find(ParticipantMailGroup::class, $fromId) : null;
        if ($from instanceof ParticipantMailGroup) {
            $group->setName($from->getName());
            $group->setShortName($from->getShortName());
            $group->setDescription($from->getDescription());
            $group->setPriority($from->getPriority());
            $group->setTwigTemplate($from->getTwigTemplate());
            $group->setCategory($from->getCategory());
            $group->setEvent($from->getEvent());
            $group->setOnlyActive($from->isOnlyActive());
            $group->setFilterExpression($from->getFilterExpression());
        }
        $form = $this->createForm(ParticipantMailGroupEditType::class, $group);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // The group form has no slug field and nothing else calls updateSlug(), so a
            // form-created group would persist with slug NULL (unlike the historic rows).
            $group->updateSlug();
            $this->em->persist($group);
            $this->em->flush();
            $this->addFlash('success', sprintf(
                'Mail group „%s" založena%s.',
                $group->getName() ?? '#'.$group->getId(),
                $group->isAutomaticMailing() ? '' : ' (automatické rozesílání je vypnuté)',
            ));

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_mail_config'));
        }

        $pageTitle = null !== $from ? sprintf('Nová mail group (kopie „%s")', $from->getName() ?? '#'.$fromId) : 'Nová mail group';

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'       => $form,
            'entity'     => $group,
            'kind'       => 'group',
            'pageTitle'  => $pageTitle,
            'page_title' => $pageTitle.' :: ADMIN',
        ]);
    }

    /**
     * Create a new ParticipantMailCategory, optionally prefilled via ?from=<id>. The auto-mail
     * engine deduplicates per category type ("one mail of this type per participant"), so a
     * duplicate type would silently split one campaign across two categories — rejected here.
     */
    public function newCategory(Request $request): Response
    {
        $category = new ParticipantMailCategory();
        $fromId = $request->query->getInt('from');
        $from = $fromId > 0 ? $this->em->find(ParticipantMailCategory::class, $fromId) : null;
        if ($from instanceof ParticipantMailCategory) {
            $category->setName($from->getName());
            $category->setShortName($from->getShortName());
            $category->setDescription($from->getDescription());
            $category->setType($from->getType());
            $category->setPriority($from->getPriority());
        }
        $form = $this->createForm(ParticipantMailCategoryEditType::class, $category);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $type = $category->getType();
            // DQL, not findOneBy: the entity is in the NONSTRICT L2 cache region, and findOneBy can
            // return a stale ghost for a row deleted outside the ORM — which would block a legitimate
            // re-creation here. Plain DQL always reads the database.
            $duplicate = empty($type) ? null
                : $this->em->createQueryBuilder()
                    ->select('c')->from(ParticipantMailCategory::class, 'c')
                    ->where('c.type = :type')->setParameter('type', $type)
                    ->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if ($duplicate instanceof ParticipantMailCategory) {
                $form->get('type')->addError(new FormError(sprintf(
                    'Kategorie s typem „%s" už existuje (#%d „%s") — typ musí být unikátní, jinak se kampaň rozpadne mezi dvě kategorie.',
                    $type,
                    $duplicate->getId() ?? 0,
                    $duplicate->getName() ?? '?',
                )));
            } else {
                $this->em->persist($category);
                $this->em->flush();
                $this->addFlash('success', sprintf('Mail kategorie „%s" založena.', $category->getName() ?? '#'.$category->getId()));

                return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_mail_config'));
            }
        }

        $pageTitle = null !== $from ? sprintf('Nová mail kategorie (kopie „%s")', $from->getName() ?? '#'.$fromId) : 'Nová mail kategorie';

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'       => $form,
            'entity'     => $category,
            'kind'       => 'category',
            'pageTitle'  => $pageTitle,
            'page_title' => $pageTitle.' :: ADMIN',
        ]);
    }

    /**
     * Create a new TwigTemplate (e.g. a kind=snippet block or a new campaign). Reuses the edit form;
     * on save it redirects to the editor so the admin immediately gets the live preview. Closes the
     * loop for snippets (the bulk composer inserts them by slug) without needing direct DB access.
     */
    public function newTemplate(Request $request): Response
    {
        $template = new TwigTemplate();
        $form = $this->createForm(TwigTemplateEditType::class, $template);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($template);
            $this->em->flush();
            $this->addFlash('success', sprintf('Twig šablona „%s" vytvořena.', $template->getName() ?? '#'.$template->getId()));

            return new RedirectResponse($this->generateUrl(
                'oswis_org_oswis_calendar_web_admin_mail_template_edit',
                ['id' => $template->getId()],
            ));
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'               => $form,
            'entity'             => $template,
            'kind'               => 'template',
            'sampleParticipants' => [],
            'pageTitle'          => 'Nová Twig šablona',
            'page_title'         => 'Nová Twig šablona :: ADMIN',
        ]);
    }

    public function editTemplate(Request $request, int $id): Response
    {
        $template = $this->em->find(TwigTemplate::class, $id)
            ?? throw $this->createNotFoundException('Twig šablona nenalezena.');
        $form = $this->createForm(TwigTemplateEditType::class, $template);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($template);
            $this->em->flush();
            $this->addFlash('success', sprintf('Twig šablona „%s" uložena.', $template->getName() ?? '#'.$id));

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_mail_config'));
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'               => $form,
            'entity'             => $template,
            'kind'               => 'template',
            'sampleParticipants' => $this->participantRepository->findSampleParticipants(30),
            'pageTitle'          => sprintf('Twig šablona: %s', $template->getName() ?? '#'.$id),
            'page_title'         => sprintf('Twig šablona: %s :: ADMIN', $template->getName() ?? '#'.$id),
        ]);
    }

    /**
     * Live preview of a mail template through the real MJML pipeline (#139 used to edit blind). POST,
     * CSRF. Renders the POSTed (unsaved) source when present — trusted Twig, same trust the editor
     * already grants — else the persisted template; against a chosen / most-recent sample participant.
     * Returns an HTML fragment for the editor's preview iframe; render errors come back as a readable
     * block, never a 500. {@see MailPreviewService}.
     */
    public function previewTemplate(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('mail_template_preview', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $template = $this->em->find(TwigTemplate::class, $id);
        if (!$template instanceof TwigTemplate) {
            return new Response(
                '<p style="font-family:sans-serif;color:#666">Šablona nenalezena.</p>',
                Response::HTTP_NOT_FOUND,
            );
        }
        // Not getInt(): the sample selector's "— nejnovější —" option posts an empty string, which
        // InputBag::getInt() rejects with a 400. Empty / non-numeric → null → pick the latest sample.
        $participantIdRaw = (string) $request->request->get('participantId', '');
        $participantId = ctype_digit($participantIdRaw) ? (int) $participantIdRaw : 0;
        $participant = $this->mailPreview->pickSampleParticipant($participantId > 0 ? $participantId : null);
        if (!$participant instanceof Participant) {
            return new Response(
                '<p style="font-family:sans-serif;color:#666">Náhled nelze vytvořit – není k dispozici žádný vzorový účastník (přihláška).</p>',
            );
        }
        $source = trim((string) $request->request->get('source', ''));
        $subject = $template->getName();
        $result = '' !== $source
            ? $this->mailPreview->renderSource($source, $participant, [], $subject)
            : $this->mailPreview->renderTemplate($template->getTemplateName(), $participant, [], $subject);

        return new Response($result['html']);
    }
}
