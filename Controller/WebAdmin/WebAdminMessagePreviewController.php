<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMailer;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailProblem;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailValidationResult;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Náhled zprávy pro editor (spec 2026-09-13 §4.5) — kontrola + vykreslení pro jednoho příjemce stejnou
 * cestou jako odeslání ({@see ParticipantManualMailer}). JSON: předmět, HTML, chyby, varování. Nic
 * neodesílá ani neukládá.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminMessagePreviewController extends AbstractController
{
    use ManualMailRequestTrait;

    public const string CSRF_ID = 'mail_preview';

    public function __construct(
        private readonly ParticipantManualMailer $mailer,
        private readonly ParticipantRepository $participantRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Platnost stránky vypršela — text si zkopíruj a stránku obnov.'], Response::HTTP_FORBIDDEN);
        }
        $participant = $this->participantRepository->find($request->request->getInt('participantId'));
        if (!$participant instanceof Participant) {
            return new JsonResponse(['error' => 'Příjemce pro náhled nebyl nalezen.'], Response::HTTP_NOT_FOUND);
        }
        $mail = $this->manualMailFromRequest($request, allowDocument: true);
        $subject = null;
        $html = null;
        try {
            $validation = $this->mailer->validate($mail, [$participant]);
            if (!$validation->hasErrors()) {
                ['subject' => $subject, 'html' => $html] = $this->mailer->preview($mail, $participant);
            }
        } catch (\Throwable $exception) {
            // Chyba prostředí (MJML, databáze…), ne textu — autorovi krátce, podrobnosti do logu.
            $this->logger->error('Náhled zprávy selhal: '.$exception->getMessage(), ['exception' => $exception]);
            $validation = new MailValidationResult();
            $validation->error('Náhled se nepodařilo vytvořit (chyba na serveru, je zapsaná v logu). Zkus to prosím znovu.');
        }

        return new JsonResponse([
            'subject'  => $subject,
            'html'     => $html,
            'errors'   => self::messages($validation->errors()),
            'warnings' => self::messages($validation->warnings()),
        ]);
    }

    /**
     * @param list<MailProblem> $problems
     *
     * @return list<string>
     */
    private static function messages(array $problems): array
    {
        return array_map(static fn (MailProblem $problem): string => $problem->message, $problems);
    }
}
