<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailCategory;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ParticipantMailCategoryEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ParticipantMailGroupEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\TwigTemplateEditType;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Web admin CRUD over the mail templating chain so admins don't have to
 * edit ParticipantMailGroup / ParticipantMailCategory / TwigTemplate rows
 * directly in the DB.
 *
 * Scope (MVP): index page + edit form per entity. CREATE / DELETE not yet
 * exposed — those workflows are rare enough to still need DB access if
 * required.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminMailConfigController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
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
            'form'       => $form,
            'entity'     => $template,
            'kind'       => 'template',
            'pageTitle'  => sprintf('Twig šablona: %s', $template->getName() ?? '#'.$id),
            'page_title' => sprintf('Twig šablona: %s :: ADMIN', $template->getName() ?? '#'.$id),
        ]);
    }
}
