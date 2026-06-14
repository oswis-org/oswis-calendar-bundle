<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailCategory;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailCategoryRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailGroupRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Interfaces\Mail\MailCategoryInterface;
use OswisOrg\OswisCoreBundle\Service\MailService;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

class ParticipantMailService
{
    /** Standalone file template for the on-update change notice (no DB category/group config needed). */
    public const REGISTRATION_CHANGED_TEMPLATE = '@OswisOrgOswisCalendar/e-mail/pages/participant-registration-changed.html.twig';

    public function __construct(
        protected EntityManagerInterface $em,
        protected MailService $mailService,
        protected ParticipantMailGroupRepository $groupRepository,
        protected ParticipantMailCategoryRepository $categoryRepository,
        protected ParticipantMailRepository $participantMailRepository,
        protected ParticipantChangeService $changeService,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Decide what an UPDATE to an existing registration should e-mail. Never confirmed yet (no prior
     * summary / change mail) → send the confirmation (summary). Otherwise diff the versioned junction
     * entities since that last mail: a real change → a "registration changed" notice listing it;
     * nothing changed → no mail (kills the old behaviour of re-sending the full "you are registered"
     * summary on every PUT). Notification failures are logged, never thrown — a change notice must not
     * break the update response. {@see ParticipantSubscriber::postWrite}.
     */
    public function notifyParticipantChanged(Participant $participant): void
    {
        $since = $this->lastRegistrationMailSentAt($participant);
        if (null === $since) {
            try {
                $this->sendSummary($participant);
            } catch (\Throwable $exception) {
                $this->logger->error(sprintf(
                    'Participant #%d update: fallback summary failed: %s',
                    $participant->getId() ?? 0,
                    $exception->getMessage(),
                ));
            }

            return;
        }
        $changes = $this->changeService->computeChanges($participant, $since);
        if (false === $changes['hasChanges']) {
            $this->logger->info(sprintf(
                'Participant #%d updated but nothing notifiable changed since %s — no mail sent.',
                $participant->getId() ?? 0,
                $since->format('c'),
            ));

            return;
        }
        $this->sendRegistrationChanged($participant, $changes);
    }

    /**
     * `sent` of the most recent summary / registration-changed mail for the participant — the diff
     * baseline. Null if neither was ever sent (→ treat an update like a first confirmation).
     */
    private function lastRegistrationMailSentAt(Participant $participant): ?\DateTimeInterface
    {
        foreach ($this->participantMailRepository->findByParticipant($participant) as $mail) {
            if (in_array($mail->getType(), [ParticipantMail::TYPE_SUMMARY, ParticipantMail::TYPE_REGISTRATION_CHANGED], true)) {
                return $mail->getSent();
            }
        }

        return null;
    }

    /**
     * Send the "registration changed" notice (the computed diff) to each of the participant's contact
     * persons via a standalone file template. No dedup — every real change should notify. Per-recipient
     * failures are logged, not thrown.
     *
     * @param array{hasChanges: bool, flags: array<string, array{added: list<string>, removed: list<string>}>, registrationsAdded: list<string>, registrationsRemoved: list<string>, contactUpdated: bool} $changes
     */
    public function sendRegistrationChanged(Participant $participant, array $changes): void
    {
        $contact = $participant->getContact();
        $event = $participant->getEvent();
        $title = 'Změna v přihlášce'.(null !== $event ? ' – '.($event->getShortName() ?? $event->getName() ?? '') : '');
        foreach ($participant->getContactPersons(true) as $contactPerson) {
            if (!$contactPerson instanceof AbstractContact || null === ($appUser = $contactPerson->getAppUser())) {
                continue;
            }
            try {
                $participantMail = new ParticipantMail($participant, $appUser, $title, ParticipantMail::TYPE_REGISTRATION_CHANGED);
                $participantMail->setPastMails($this->participantMailRepository->findByParticipant($participant));
                $templatedEmail = $participantMail->getTemplatedEmail();
                $data = [
                    'participant'    => $participant,
                    'appUser'        => $appUser,
                    'contact'        => $contact,
                    // f (vykání) JEN z participant.formal/kategorie — contact/appUser sloupec `formal` NEEXISTUJE,
                    // takže šablonový default `contact.formal ?? appUser.formal ?? true` vykal i tykací kategorie.
                    'f'              => $participant->isFormal(true) ?? false,
                    'salutationName' => $contact instanceof Person ? $contact->getSalutationName() : $contact?->getName(),
                    'changes'        => $changes,
                    'type'           => ParticipantMail::TYPE_REGISTRATION_CHANGED,
                    'depositAmount'  => $participant->getRemainingDeposit(),
                    'restAmount'     => $participant->getRemainingPriceRest(),
                ];
                $data = $this->embedQrPayments($templatedEmail, $participant, $data, true);
                $this->em->persist($participantMail);
                $this->mailService->sendEMail($participantMail, self::REGISTRATION_CHANGED_TEMPLATE, $data);
                $this->em->flush();
            } catch (\Throwable $exception) {
                $this->logger->error(sprintf(
                    'Registration-changed mail for participant #%d to user #%d failed: %s',
                    $participant->getId() ?? 0,
                    $appUser->getId() ?? 0,
                    $exception->getMessage(),
                ));
            }
        }
    }

    /**
     * @param Participant $participant
     *
     * @throws OswisException
     */
    public function sendSummary(Participant $participant): void
    {
        $sent = 0;
        $contactPersons = $participant->getContactPersons(true);
        foreach ($contactPersons as $contactPerson) {
            if (!($contactPerson instanceof AbstractContact) || null === ($appUser = $contactPerson->getAppUser())) {
                continue;
            }
            try {
                $this->sendSummaryToUser($participant, $appUser, ParticipantMail::TYPE_SUMMARY);
                $sent++;
            } catch (OswisException|NotFoundException|NotImplementedException|InvalidTypeException $exception) {
                $participantId = $participant->getId();
                $userId = $appUser->getId();
                $message = $exception->getMessage();
                $this->logger->error(
                    "ERROR: Not sent summary for participant '$participantId' to user '$userId' ($message)."
                );
            }
        }
        $this->logger->debug("SENT $sent from ".$contactPersons->count());
        if (1 > $sent && $contactPersons->count() > 0) {
            throw new OswisException("Nepodařilo se odeslat potvrzovací e-mail.");
        }
    }

    /**
     * @param Participant $participant
     * @param AppUser     $appUser
     * @param string      $type
     * @param ParticipantToken|null $participantToken
     *
     * @throws NotFoundException
     * @throws NotImplementedException
     * @throws OswisException
     * @throws InvalidTypeException
     */
    public function sendSummaryToUser(
        Participant $participant,
        AppUser $appUser,
        string $type,
        ?ParticipantToken $participantToken = null
    ): void {
        $isIS = false;
        if (null !== $participantToken
            && (!$participantToken->isParticipant($participant)
                || !$participantToken->isAppUser($appUser))) {
            throw new OswisException('Token není kompatibilní s přihláškou.');
        }
        if (null === ($mailCategory = $this->getMailCategoryByType($type))) {
            throw new NotImplementedException($type, 'u e-mailů k přihláškám');
        }
        if (null === ($group = $this->getMailGroupByCategory($participant, $mailCategory))
            || null === ($twigTemplate
                = $group->getTwigTemplate())) {
            throw new NotFoundException('Skupina nebo šablona e-mailů nebyla nalezena.');
        }
        $appUser = ($participantToken?->getAppUser()) ?? $participant->getAppUser();
        if (null === $appUser) {
            throw new NotFoundException('Uživatel nebyl nalezen.');
        }
        $title = $twigTemplate->getName() ?? 'Přihláška na akci';
        if ($participant->getDeletedAt()) {
            $title = "Shrnutí smazané přihlášky";
        }
        $participantMail = new ParticipantMail($participant, $appUser, $title, $type, $participantToken);
        $participantMail->setParticipantMailCategory($mailCategory);
        $participantMail->setPastMails($this->participantMailRepository->findByParticipant($participant));
        $contact = $participant->getContact();
        $data = [
            'participant' => $participant,
            'appUser' => $appUser,
            'contact' => $contact,
            'salutationName' => $contact instanceof Person ? $contact->getSalutationName() : $contact?->getName(),
            'category' => $mailCategory,
            'type' => $type,
            'participantToken' => $participantToken,
            'isIS' => $isIS,
            'registrations' => $participant->getParticipantRegistrations(true),
            // Tykání/vykání dle kategorie účastníka (override > kategorie), ne dle (neexistujícího)
            // contact.formal v base šabloně, který by spadl na default vykání.
            'f' => $participant->isFormal(true) ?? false,
        ];
        $templatedEmail = $participantMail->getTemplatedEmail();
        if (ParticipantMail::TYPE_SUMMARY === $type) {
            $data = $this->embedQrPayments($templatedEmail, $participant, $data);
            $this->attachIcsCalendar($templatedEmail, $participant);
        }
        $this->em->persist($participantMail);
        $templateName = $twigTemplate->getTemplateName();
        $this->mailService->sendEMail($participantMail, $templateName, $data);
        $this->em->flush();
    }

    /**
     * Re-send an existing system mail entry (summary / payment / activation).
     * Generates a fresh ParticipantMail row + delivery — no DB row is mutated
     * in place; the admin sees both the original send and the resend in the
     * timeline. The originating Participant/AppUser pair drives the resend so
     * the message goes to whoever the original recipient was.
     *
     * @throws OswisException
     * @throws NotImplementedException
     * @throws NotFoundException
     * @throws InvalidTypeException
     */
    public function resend(ParticipantMail $existingMail): void
    {
        $participant = $existingMail->getParticipant();
        $appUser = $existingMail->getAppUser();
        $type = $existingMail->getType();
        if (null === $participant || null === $appUser || null === $type) {
            throw new OswisException('Nelze znovu odeslat: chybí účastník, uživatel nebo typ.');
        }
        $this->sendSummaryToUser($participant, $appUser, $type);
    }

    public function getMailCategoryByType(?string $type): ?ParticipantMailCategory
    {
        return $this->categoryRepository->findByType(''.$type);
    }

    public function getMailGroupByCategory(
        Participant $participant,
        MailCategoryInterface $category
    ): ?ParticipantMailGroup {
        return $this->groupRepository->findByUser($participant, $category);
    }

    /**
     * Attach iCalendar (RFC 5545) .ics se základní VEVENT pro akci.
     *
     * Gmail / Outlook / Apple Mail / Thunderbird umí .ics nabídnout
     * jednoklikem „Přidat do kalendáře". Bez závislosti na schema.org
     * microdata, které dnes prakticky parsuje jen Apple Mail.
     */
    public function attachIcsCalendar(TemplatedEmail $templatedEmail, Participant $participant): void
    {
        $event = $participant->getEvent();
        if (null === $event) {
            return;
        }
        $startDate = $event->getStartDateTime();
        $endDate = $event->getEndDateTime();
        if (null === $startDate || null === $endDate) {
            return;
        }
        $icsContent = $this->buildIcsContent($participant, $event, $startDate, $endDate);
        $templatedEmail->attach($icsContent, 'akce.ics', 'text/calendar; charset=utf-8; method=PUBLISH');
    }

    private function buildIcsContent(
        Participant $participant,
        Event $event,
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
    ): string {
        $utc = new \DateTimeZone('UTC');
        $fmt = static fn(\DateTimeInterface $dt): string => (clone \DateTimeImmutable::createFromInterface($dt))
            ->setTimezone($utc)
            ->format('Ymd\THis\Z');
        $now = $fmt(new \DateTimeImmutable());
        $uid = sprintf(
            'oswis-participant-%d-event-%d@%s',
            $participant->getId() ?? 0,
            $event->getId() ?? 0,
            $this->getMessageIdDomain(),
        );
        $summary = $this->escapeIcsText($event->getShortName() ?? $event->getName() ?? 'Akce');
        $place = $event->getPlace();
        $locationParts = [];
        if ($place !== null) {
            if ($place->getName()) {
                $locationParts[] = $place->getName();
            }
            if ($place->getStreetAddress()) {
                $locationParts[] = $place->getStreetAddress();
            }
            if ($place->getCity()) {
                $locationParts[] = $place->getCity();
            }
        }
        $location = $this->escapeIcsText(implode(', ', $locationParts));
        $description = $this->escapeIcsText(sprintf(
            'Vaše přihláška na akci %s (ID %d).',
            $event->getName() ?? 'OSWIS',
            $participant->getId() ?? 0,
        ));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//OSWIS//OSWIS//CS',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$now,
            'DTSTART:'.$fmt($startDate),
            'DTEND:'.$fmt($endDate),
            'SUMMARY:'.$summary,
        ];
        if ('' !== $location) {
            $lines[] = 'LOCATION:'.$location;
        }
        if ('' !== $description) {
            $lines[] = 'DESCRIPTION:'.$description;
        }
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // RFC 5545: lines longer than 75 octets MUST be folded. Simple impl:
        // pokud > 75 bytes, fold s leading TAB (jak doporučuje RFC).
        $folded = [];
        foreach ($lines as $line) {
            $folded[] = $this->foldIcsLine($line);
        }

        return implode("\r\n", $folded)."\r\n";
    }

    /**
     * RFC 5545 §3.3.11 — text escape: backslash, semicolon, comma, newline.
     */
    private function escapeIcsText(string $text): string
    {
        $text = str_replace(['\\', "\r\n", "\n", "\r"], ['\\\\', '\\n', '\\n', '\\n'], $text);

        return str_replace([';', ','], ['\\;', '\\,'], $text);
    }

    /**
     * RFC 5545 §3.1 — fold lines longer than 75 octets.
     */
    private function foldIcsLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $remaining = $line;
        while (strlen($remaining) > 75) {
            $out .= substr($remaining, 0, 75)."\r\n\t";
            $remaining = substr($remaining, 75);
        }

        return $out.$remaining;
    }

    /**
     * Doména pro UID iCal — používáme stejnou jako pro Message-ID
     * (oswis.<domain>), aby UID byly globálně unikátní a vázané k naší doméně.
     */
    private function getMessageIdDomain(): string
    {
        return 'oswis.seznamovakup.cz';
    }

    public function embedQrPayments(TemplatedEmail $templatedEmail, Participant $participant, array $mailData, bool $remainingOnly = false): array
    {
        $participantId = $participant->getId();
        $participantContactSlug = $participant->getContact()?->getSlug();
        $eventId = $participant->getEvent()?->getId();
        $qrComment = "$participantContactSlug, ID $participantId, akce $eventId";
        foreach (
            [
                'depositQr' => ['deposit' => true, 'rest' => false],
                'restQr' => ['deposit' => false, 'rest' => true],
            ] as $key => $opts
        ) {
            $qrPng = $remainingOnly
                ? $participant->generateRemainingQrPng($opts['deposit'], $opts['rest'], $qrComment)
                : $participant->generateQrPng($opts['deposit'], $opts['rest'], $qrComment);
            if ($qrPng) {
                $templatedEmail->embed($qrPng, $key, 'image/png');
                $mailData[$key] = "cid:$key";
            }
        }

        return $mailData;
    }

    /**
     * @param Event|null  $event
     * @param string|null $type
     * @return Collection<int, ParticipantMailGroup>|null
     */
    public function getAutoMailGroups(?Event $event = null, ?string $type = null): ?Collection
    {
        return $this->groupRepository->findAutoMailGroups($event, $type);
    }

    /**
     * @param ParticipantPayment $payment
     *
     * @throws OswisException
     */
    public function sendPaymentConfirmation(ParticipantPayment $payment): void
    {
        $sent = 0;
        $paymentId = $payment->getId();
        if (null === ($participant = $payment->getParticipant())) {
            $this->logger->warning("Not sending payment '$paymentId' confirmation because participant is not set.");

            return;
        }
        foreach ($contactPersons = $participant->getContactPersons(true) as $contactPerson) {
            if (!($contactPerson instanceof AbstractContact) || null === ($appUser = $contactPerson->getAppUser())) {
                continue;
            }
            try {
                $this->sendPaymentConfirmationToUser($payment, $appUser);
                $sent++;
            } catch (NotFoundException|NotImplementedException|InvalidTypeException $exception) {
                /** @phpstan-ignore-next-line */
                $userId = $contactPerson->getAppUser()?->getId();
                $message = $exception->getMessage();
                $this->logger->error(
                    "ERROR: Not sent confirmation of payment '$paymentId' to user '$userId' ($message)."
                );
            }
        }
        if (1 > $sent && $contactPersons->count() > 0) {
            throw new OswisException("Nepodařilo se odeslat potvrzovací e-mail o platbě účastníkovi.");
        }
    }

    /**
     * @param ParticipantPayment $payment
     * @param AppUser $appUser
     *
     * @throws InvalidTypeException
     * @throws NotFoundException
     * @throws NotImplementedException
     */
    public function sendPaymentConfirmationToUser(ParticipantPayment $payment, AppUser $appUser): void
    {
        $participant = $payment->getParticipant();
        if (null === $participant) {
            return;
        }
        if (null === ($mailCategory = $this->getMailCategoryByType(ParticipantMail::TYPE_PAYMENT))) {
            throw new NotImplementedException(ParticipantMail::TYPE_PAYMENT, 'u e-mailů k přihláškám');
        }
        if (null === ($group = $this->getMailGroupByCategory($participant, $mailCategory))
            || null === ($twigTemplate
                = $group->getTwigTemplate())) {
            $groupName = $group?->getName();
            $templateName = isset($twigTemplate) ? $twigTemplate->getName() : null;
            throw new NotFoundException("Skupina '$groupName' nebo šablona '$templateName' e-mailů nebyla nalezena.");
        }
        $title = $payment->getNumericValue() < 0 ? 'Vrácení/oprava platby' : 'Přijetí platby';
        $participantMail = new ParticipantMail($participant, $appUser, $title, ParticipantMail::TYPE_PAYMENT);
        $participantMail->setParticipantMailCategory($mailCategory);
        $participantMail->setPastMails($this->participantMailRepository->findByParticipant($participant));
        $contact = $participant->getContact();
        $data = [
            'payment' => $payment,
            'participant' => $participant,
            'appUser' => $appUser,
            'contact' => $contact,
            'salutationName' => $contact instanceof Person ? $contact->getSalutationName() : $contact?->getName(),
            'category' => $mailCategory,
            'type' => ParticipantMail::TYPE_PAYMENT,
            'isIS' => false,
            'f' => $participant->isFormal(true) ?? false,
        ];
        $this->em->persist($participantMail);
        $this->em->persist($payment);
        $templateName = $twigTemplate->getTemplateName();
        $this->mailService->sendEMail($participantMail, $templateName, $data);
        if ($participantMail->getSent() && !$payment->isConfirmedByMail()) {
            $payment->setConfirmedByMailAt($participantMail->getSent());
        }
        $this->em->flush();
    }

    /**
     * @param Participant $participant
     * @param ParticipantMailGroup $group
     *
     * @throws OswisException
     */
    /**
     * Send an ad-hoc mail composed manually by an admin to a participant.
     *
     * Type is prefixed with "ad-hoc-" so CommunicationChannel detection on the
     * timeline picks it up as AD_HOC_MAIL rather than SYSTEM_MAIL.
     *
     * @return array{sent: int, errors: list<string>}
     * @throws OswisException when zero mails went out at all.
     */
    public function sendAdHoc(
        Participant $participant,
        string $subject,
        string $bodyHtml,
        ?string $adminName = null,
    ): array {
        $contactPersons = $participant->getContactPersons(true);
        $sent = 0;
        $errors = [];

        foreach ($contactPersons as $contactPerson) {
            if (!$contactPerson instanceof AbstractContact) {
                continue;
            }
            $appUser = $contactPerson->getAppUser();
            if (null === $appUser) {
                continue;
            }
            try {
                // Per-iteration unique type: date('YmdHis') has 1-second
                // granularity, two contacts processed in the same second
                // would collide on this string. Append the current count.
                $type = sprintf('ad-hoc-%s-%d', date('YmdHis'), $sent + count($errors) + 1);
                $participantMail = new ParticipantMail($participant, $appUser, $subject, $type);
                $participantMail->setPastMails($this->participantMailRepository->findByParticipant($participant));
                // Ad-hoc compose = admin píše ručně, ne systémový automat —
                // mark before send aby MailerSubscriber nastavil Auto-Submitted: no.
                $participantMail->markAsManual();

                $contact = $participant->getContact();
                $data = [
                    'participant'    => $participant,
                    'appUser'        => $appUser,
                    'contact'        => $contact,
                    'salutationName' => $contact instanceof Person ? $contact->getSalutationName() : $contact?->getName(),
                    'subject'        => $subject,
                    'bodyHtml'       => $bodyHtml,
                    'adminName'      => $adminName,
                    'type'           => $type,
                    'f'              => $participant->isFormal(true) ?? false,
                ];

                $this->em->persist($participantMail);
                $this->mailService->sendEMail(
                    $participantMail,
                    '@OswisOrgOswisCalendar/e-mail/pages/participant-ad-hoc.html.twig',
                    $data,
                );
                $this->em->flush();
                $sent++;
            } catch (\Throwable $e) {
                $errors[] = $appUser->getEmail().': '.$e->getMessage();
                $this->logger->error(
                    sprintf('Ad-hoc mail to participant %d failed: %s', $participant->getId() ?? 0, $e->getMessage()),
                );
            }
        }

        if (0 === $sent) {
            throw new OswisException(
                'Ad-hoc e-mail nikam neodeslán'
                .($contactPersons->count() === 0 ? ' (účastník nemá ani jeden kontakt s registrovaným uživatelem).' : '. '.implode(' | ', $errors)),
            );
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    public function sendMessage(Participant $participant, ParticipantMailGroup $group): void
    {
        $participantId = $participant->getId();
        $type = $group->getType();
        $sent = 0;
        if ($this->participantMailRepository->countSent($participant, ''.$type) > 0) {
            $this->logger->error(
                "ERROR: Not sent message '$type' for participant '$participantId' to user (message already sent)."
            );

            return;
        }
        foreach ($contactPersons = $participant->getContactPersons(true) as $contactPerson) {
            if ($contactPerson instanceof AbstractContact && null !== ($appUser = $contactPerson->getAppUser())) {
                try {
                    if ($this->sendMessageToUser($participant, $appUser, $group)) {
                        $sent++;
                    }
                } catch (NotFoundException|NotImplementedException|InvalidTypeException $exception) {
                    $userId = $appUser->getId();
                    $message = $exception->getMessage();
                    $this->logger->error(
                        "ERROR: Not sent message '$type' for participant '$participantId' to user '$userId' ($message)."
                    );
                }
            }
        }
        if (1 > $sent && $contactPersons->count() > 0) {
            throw new OswisException("Nepodařilo se odeslat e-mail typu '$type'.");
        }
    }

    /**
     * @param Participant $participant
     * @param AppUser     $appUser
     * @param ParticipantMailGroup $group
     *
     * @throws InvalidTypeException
     * @throws NotFoundException
     * @throws NotImplementedException
     */
    public function sendMessageToUser(Participant $participant, AppUser $appUser, ParticipantMailGroup $group): bool
    {
        if (null === ($mailCategory = $group->getCategory())) {
            throw new NotImplementedException($group->getType(), 'u e-mailů k přihláškám');
        }
        if (null === ($twigTemplate = $group->getTwigTemplate())) {
            throw new NotFoundException('Šablona e-mailů nebyla nalezena.');
        }
        $defaultTitle = "Informace k akci".(null !== $group->getEvent() ? $group->getEvent()->getShortName() : '');
        $title = $twigTemplate->getName() ?? $defaultTitle;
        $participantMail = new ParticipantMail($participant, $appUser, $title, $group->getType());
        $participantMail->setParticipantMailCategory($mailCategory);
        $participantMail->setPastMails($this->participantMailRepository->findByParticipant($participant));
        $contact = $participant->getContact();
        $data = [
            'participant' => $participant,
            'appUser' => $appUser,
            'contact' => $contact,
            'salutationName' => $contact instanceof Person ? $contact->getSalutationName() : $contact?->getName(),
            'category' => $mailCategory,
            'type' => $group->getType(),
            'f' => $participant->isFormal(true) ?? false,
        ];
        $this->em->persist($participantMail);
        $templateName = $twigTemplate->getTemplateName();
        $this->mailService->sendEMail($participantMail, $templateName, $data);
        $this->em->flush();

        // True success signal — MailService::sendEMail swallows transport errors (sets statusMessage,
        // leaves sent = NULL). Callers must not count a failed delivery as sent.
        return $participantMail->isSent();
    }

}
