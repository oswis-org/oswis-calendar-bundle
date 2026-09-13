<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Mail\Rendering\MailRenderer;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailValidationResult;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailValidator;
use OswisOrg\OswisCoreBundle\Service\MailService;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Ruční zprávy přihláškám — JEDNA cesta pro „Novou zprávu" jednomu účastníkovi i pro hromadný mail
 * (spec 2026-09-13 §5). Kontrola, náhled i odeslání používají stejný kontext
 * ({@see ParticipantMailContextFactory::createNeutral()}) a stejné vykreslení ({@see MailRenderer}),
 * takže co prošlo kontrolou a náhledem, to odejde.
 *
 * PROČ: „Nová zpráva" posílala Twig doslova (`vyhrál{{a}}`, 12. 9. 2026) a hromadný mail měl vlastní,
 * odlišnou kopii téhož — opravy jedné cesty se do druhé nedostaly.
 */
final class ParticipantManualMailer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailService $mailService,
        private readonly ParticipantMailRepository $participantMailRepository,
        private readonly ParticipantMailContextFactory $contextFactory,
        private readonly MailRenderer $renderer,
        private readonly MailValidator $validator,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Kontrola pro VŠECHNY příjemce — Twig pro každého, MJML pro každou odlišnou skladbu bloků.
     * (Dřív se hromadný mail zkoušel jen na prvním příjemci.)
     *
     * @param iterable<Participant> $participants
     */
    public function validate(ParticipantManualMail $mail, iterable $participants): MailValidationResult
    {
        $recipients = $this->recipients($participants, 'kontrola', $mail->adminName);

        return null !== $mail->templateSlug
            ? $this->validator->validateTemplate($mail->templateSlug, $recipients, $mail->subject)
            : $this->validator->validateMessage($mail->subject, $mail->body, $recipients);
    }

    /**
     * Kontrola šablony kampaně nebo bloku před uložením — na vzorových přihláškách, se stejným
     * kontextem, jaký dostane ruční zpráva (nález B5: překlep se uložil a rozbilo se až odeslání).
     *
     * @param iterable<Participant> $participants
     */
    public function validateTemplateSource(string $source, iterable $participants): MailValidationResult
    {
        return $this->validator->validateTemplateSource($source, $this->recipients($participants, 'kontrola'));
    }

    /**
     * Náhled pro jednoho příjemce — totéž HTML, které odejde. Chybu vykreslení hází (volající má
     * nejdřív zavolat {@see validate()} a chyby ukázat místo náhledu).
     *
     * @return array{subject: string, html: string}
     */
    public function preview(ParticipantManualMail $mail, Participant $participant): array
    {
        $context = $this->context($mail, $participant, null, 'nahled');
        [$template, $data] = $this->templateAndData($mail, $context);

        return [
            'subject' => $this->renderer->renderSubject($mail->subject, $context),
            'html'    => $this->twig->render($template, $data),
        ];
    }

    /**
     * Odešle zprávu všem adresám přihlášky (osoba = 1 adresa, organizace = N) a zapíše ji do historie.
     * Doklad doručení je jen `isSent()` — SMTP selhává tiše (MailService chybu zapíše do záznamu).
     *
     * @return array{sent: int, errors: list<string>}
     */
    public function send(ParticipantManualMail $mail, Participant $participant, string $type, ?ParticipantMailBulk $bulk = null): array
    {
        $appUsers = ParticipantMailContextFactory::recipientUsers($participant);
        if ([] === $appUsers) {
            return ['sent' => 0, 'errors' => ['přihláška nemá adresáta s aktivovaným účtem, není komu psát']];
        }
        $sent = 0;
        $errors = [];
        foreach ($appUsers as $appUser) {
            try {
                $participantMail = $this->sendToUser($mail, $participant, $appUser, $type, $bulk);
                if ($participantMail->isSent()) {
                    ++$sent;
                } else {
                    $errors[] = sprintf('%s: %s', $appUser->getEmail(), $participantMail->getStatusMessage() ?? 'neodesláno');
                }
            } catch (\Throwable $exception) {
                $errors[] = sprintf('%s: %s', $appUser->getEmail(), $exception->getMessage());
                $this->logger->error(sprintf(
                    'Ruční zpráva „%s" → přihláška #%d (%s) neodešla: %s',
                    $type,
                    $participant->getId() ?? 0,
                    $appUser->getEmail(),
                    $exception->getMessage(),
                ));
            }
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    private function sendToUser(ParticipantManualMail $mail, Participant $participant, AppUser $appUser, string $type, ?ParticipantMailBulk $bulk): ParticipantMail
    {
        $context = $this->context($mail, $participant, $appUser, $type);
        // Vykreslit PŘED zápisem do historie: chyba nesmí nechat záznam, který nic neodeslal,
        // a do mailu nesmí odejít nevyhodnocený Twig (dřív odešel „záložní" syrový text i se {{ … }}).
        $subject = $this->renderer->renderSubject($mail->subject, $context);
        [$template, $data] = $this->templateAndData($mail, $context);
        $participantMail = new ParticipantMail($participant, $appUser, $subject, $type);
        if (null !== $bulk) {
            $participantMail->setBulk($bulk);
        }
        $participantMail->setPastMails($this->participantMailRepository->findByParticipant($participant));
        // Ruční zpráva, ne automat → MailerSubscriber nastaví Auto-Submitted: no.
        $participantMail->markAsManual();
        $this->em->persist($participantMail);
        $this->mailService->sendEMail($participantMail, $template, $data);

        return $participantMail;
    }

    /**
     * @param iterable<Participant> $participants
     *
     * @return list<array{label: string, context: array<string, mixed>}>
     */
    private function recipients(iterable $participants, string $type, ?string $adminName = null): array
    {
        $recipients = [];
        foreach ($participants as $participant) {
            $recipients[] = [
                'label'   => self::label($participant),
                'context' => $this->contextFactory->createNeutral($participant, null, ['adminName' => $adminName, 'type' => $type]),
            ];
        }

        return $recipients;
    }

    /** @return array<string, mixed> */
    private function context(ParticipantManualMail $mail, Participant $participant, ?AppUser $appUser, string $type): array
    {
        return $this->contextFactory->createNeutral($participant, $appUser, ['adminName' => $mail->adminName, 'type' => $type]);
    }

    /**
     * Uložená šablona se pošle, jak je; text zprávy se vykreslí do obalu {@see MailRenderer::WRAPPER_TEMPLATE}.
     *
     * @param array<string, mixed> $context
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function templateAndData(ParticipantManualMail $mail, array $context): array
    {
        if (null !== $mail->templateSlug) {
            return [$mail->templateSlug, $context];
        }

        return [MailRenderer::WRAPPER_TEMPLATE, array_merge($context, ['mjmlBody' => $this->renderer->renderBody($mail->body, $context)])];
    }

    /**
     * Jméno z čistého getteru — `getName()` entitu mění (NameTrait::updateName → sortableName)
     * a při uložení hromadného mailu by se to zapsalo do stovek kontaktů.
     */
    private static function label(Participant $participant): string
    {
        $contact = $participant->getContact();

        return trim(sprintf('#%d %s', $participant->getId() ?? 0, $contact instanceof Person ? $contact->getFullName() : ''));
    }
}
