<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCoreBundle\Entity\MailTestInbox\MailTestInbox;
use OswisOrg\OswisCoreBundle\Mail\Quota\MailDailyQuota;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Komunikace → Nastavení (spec e-mailů §7, dávka 3.4): zkušební schránky pro „Poslat zkoušku" a přehled denního
 * limitu (ten se mění v prostředí serveru, tady je jen vidět). Přidání a odebrání = POST s CSRF.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminMailSettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailDailyQuota $quota,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function index(): Response
    {
        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_settings/index.html.twig', [
            'title'     => 'Nastavení komunikace :: ADMIN',
            'pageTitle' => 'Nastavení komunikace',
            'inboxes'   => $this->em->getRepository(MailTestInbox::class)->findBy([], ['email' => 'ASC']),
            'limit'     => ['sent' => $this->quota->sentToday(), 'remaining' => $this->quota->remainingForBulk(), 'bulk' => $this->quota->bulkLimit(), 'hard' => $this->quota->hardLimit()],
        ]);
    }

    public function addInbox(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mail_test_inbox_add', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $email = mb_strtolower(trim($request->request->getString('email')));
        if ('' === $email || count($this->validator->validate($email, new Email(mode: Email::VALIDATION_MODE_STRICT))) > 0) {
            $this->addFlash('danger', sprintf('„%s" není platná e-mailová adresa.', $email));

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_mail_settings');
        }
        if (null !== $this->em->getRepository(MailTestInbox::class)->findOneBy(['email' => $email])) {
            $this->addFlash('warning', sprintf('Schránka %s už mezi zkušebními je.', $email));

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_mail_settings');
        }
        $this->em->persist(new MailTestInbox($email, $request->request->getString('note')));
        $this->em->flush();
        $this->addFlash('success', sprintf('Zkušební schránka %s přidána.', $email));

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_mail_settings');
    }

    public function removeInbox(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('mail_test_inbox_remove_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $inbox = $this->em->find(MailTestInbox::class, $id);
        if ($inbox instanceof MailTestInbox) {
            $this->em->remove($inbox);
            $this->em->flush();
            $this->addFlash('success', sprintf('Zkušební schránka %s odebrána.', $inbox->getEmail()));
        }

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_mail_settings');
    }
}
