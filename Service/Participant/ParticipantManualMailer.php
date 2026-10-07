<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Mail\Delivery\DeliveryKey;
use OswisOrg\OswisCoreBundle\Mail\Rendering\MailRenderer;
use OswisOrg\OswisCoreBundle\Mail\Rendering\NonBreakingSpaces;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailValidationResult;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailValidator;
use OswisOrg\OswisCoreBundle\Service\MailService;
use OswisOrg\OswisCoreBundle\Service\SystemMailService;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCoreBundle\Entity\MailAttachment\MailAttachment;
use OswisOrg\OswisCoreBundle\Mail\Attachment\AttachedFile;
use OswisOrg\OswisCoreBundle\Mail\Attachment\MailAttachmentStore;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        private readonly MailService $mailService,
        private readonly ParticipantMailRepository $participantMailRepository,
        private readonly ParticipantMailContextFactory $contextFactory,
        private readonly MailRenderer $renderer,
        private readonly MailValidator $validator,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly SystemMailService $systemMailService,
        private readonly EntityManagerInterface $em,
        private readonly MailAttachmentStore $attachmentStore,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Přílohy zprávy rozdělené podle způsobu (dávka 3.5): soubory k přiložení, odkazy ke stažení (do seznamu
     * „Ke stažení" pod textem), popis do záznamu odeslaného mailu a ID, které v úložišti chybí.
     *
     * @return array{soubory: list<AttachedFile>, odkazy: list<array{url: string, name: string, sizeLabel: string}>, popis: list<array{id: int, name: string, size: int, mode: string}>, chybi: list<int>, prilozeno: int, odkazem: list<MailAttachment>}
     */
    public function prilohy(ParticipantManualMail $mail): array
    {
        $vysledek = ['soubory' => [], 'odkazy' => [], 'popis' => [], 'chybi' => [], 'prilozeno' => 0, 'odkazem' => []];
        foreach ($mail->attachments as $priloha) {
            $soubor = $this->em->find(MailAttachment::class, $priloha['id']);
            if (!$soubor instanceof MailAttachment) {
                $vysledek['chybi'][] = $priloha['id'];
                continue;
            }
            $vysledek['popis'][] = ['id' => $priloha['id'], 'name' => $soubor->getOriginalName(), 'size' => $soubor->getSize(), 'mode' => $priloha['mode']];
            if (ParticipantManualMail::ODKAZ === $priloha['mode']) {
                $vysledek['odkazem'][] = $soubor;
                $vysledek['odkazy'][] = [
                    'url'       => $this->urlGenerator->generate('oswis_org_oswis_core_mail_attachment_public', ['token' => $soubor->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
                    'name'      => $soubor->getOriginalName(),
                    'sizeLabel' => $soubor->getSizeLabel(),
                ];
                continue;
            }
            $vysledek['soubory'][] = new AttachedFile($this->attachmentStore->absolutePath($soubor), $soubor->getOriginalName(), $soubor->getMimeType());
            $vysledek['prilozeno'] += $soubor->getSize();
        }

        return $vysledek;
    }

    /** Soubory posílané odkazem začnou být ke stažení (zpráva s odkazem právě odchází). Uloží volající. */
    public function zverejnitOdkazy(ParticipantManualMail $mail): void
    {
        foreach ($this->prilohy($mail)['odkazem'] as $soubor) {
            $soubor->zverejnit();
        }
    }

    /** Chybějící soubor nebo přílohy nad limit = chyba zprávy (nejde ji odeslat ani zkusit). */
    private function zkontrolovatPrilohy(ParticipantManualMail $mail, MailValidationResult $result): void
    {
        if ([] === $mail->attachments) {
            return;
        }
        $prilohy = $this->prilohy($mail);
        foreach ($prilohy['chybi'] as $id) {
            $result->error(sprintf('Příloha #%d už v úložišti není — odeber ji a nahraj soubor znovu.', $id));
        }
        if ($prilohy['prilozeno'] > MailAttachmentStore::MAX_CELKEM) {
            $result->error(sprintf(
                'Přílohy mají dohromady %s, do jedné zprávy se vejde nejvýš %d MB. Pošli některé jako odkaz ke stažení.',
                MailAttachment::velikost($prilohy['prilozeno']),
                MailAttachmentStore::MAX_CELKEM / 1048576,
            ));
        }
    }

    /** Typ systémového e-mailu se zkouškou sobě. */
    public const string TYPE_TEST = 'zkouska-zpravy';

    public const string TEST_PREFIX = '[ZKOUŠKA] ';

    /**
     * Zkouška sobě (spec §5.3 a): zpráva vykreslená PŘESNĚ jako pro `$participant` (jeho první adresa s aktivovaným
     * účtem, jinak bez adresáta — oslovení z přihlášky), odeslaná na `$address` s předmětem „[ZKOUŠKA] …".
     * Jde jako systémový e-mail: uloží se a počítá do limitu, ale NEzapíše se do historie přihlášky a nejde do
     * archivu. Které adresy smí dostat zkoušku, hlídá volající (správce + zkušební schránky z Nastavení).
     *
     * @return string|null chyba, nebo null = odesláno
     */
    public function sendTest(ParticipantManualMail $mail, Participant $participant, string $address): ?string
    {
        $appUser = ParticipantMailContextFactory::recipientUsers($participant)[0] ?? null;
        try {
            $context = $this->context($mail, $participant, $appUser, self::TYPE_TEST);
            $subject = self::TEST_PREFIX.$this->renderer->renderSubject($mail->subject, $context);
            [$template, $data] = $this->templateAndData($mail, $context);
            $prilohy = $this->prilohy($mail);
            $data['prilohyKeStazeni'] = $prilohy['odkazy'];
            // Zkouška posílá skutečné odkazy — musí jít otevřít.
            $this->zverejnitOdkazy($mail);
            $zaznam = $this->systemMailService->send(self::TYPE_TEST, $address, $subject, $template, $data, archiveCopy: false, manual: true, attachments: $prilohy['soubory']);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('Zkouška zprávy na %s (vykresleno pro přihlášku #%d) neodešla: %s', $address, $participant->getId() ?? 0, $exception->getMessage()));

            return $exception->getMessage();
        }

        return $zaznam->isSent() ? null : ($zaznam->getStatusMessage() ?? 'neodesláno');
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
        if (null !== ($dokument = self::zdrojDokumentu($mail))) {
            $result = $this->validator->validateTemplateSource($dokument, $recipients, '' !== $mail->subject ? $mail->subject : null);
        } else {
            $result = null !== $mail->templateSlug
                ? $this->validator->validateTemplate($mail->templateSlug, $recipients, $mail->subject)
                : $this->validator->validateMessage($mail->subject, $mail->body, $recipients);
        }
        $this->zkontrolovatPrilohy($mail, $result);

        return $result;
    }

    /**
     * Kontrola šablony kampaně nebo bloku před uložením — na vzorových přihláškách, se stejným
     * kontextem, jaký dostane ruční zpráva (nález B5: překlep se uložil a rozbilo se až odeslání).
     *
     * @param iterable<Participant> $participants
     */
    public function validateTemplateSource(string $source, iterable $participants, ?string $subject = null): MailValidationResult
    {
        return $this->validator->validateTemplateSource($source, $this->recipients($participants, 'kontrola'), $subject);
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
        if (null !== ($dokument = self::zdrojDokumentu($mail))) {
            // Neuložená kampaň z editoru šablony: vykreslit TAK, jak ji při odeslání načte
            // DatabaseLoader (syrový zdroj, žádná obálka navíc) — a předmět z pole „Předmět" šablony
            // tak, jak ho vykreslí odeslání (akce je v něm proměnná, nic se nepřilepí).
            $predmet = $this->renderer->renderTemplateSubject($mail->subject, $context, '');

            return [
                'subject' => $predmet,
                'html'    => NonBreakingSpaces::apply($this->twig->createTemplate($dokument)->render(MailPreviewService::sPredmetem($context, $predmet))),
            ];
        }
        [$template, $data] = $this->templateAndData($mail, $context);
        $data['prilohyKeStazeni'] = $this->prilohy($mail)['odkazy'];
        $predmet = $this->renderer->renderSubject($mail->subject, $context);

        return [
            'subject' => $predmet,
            'html'    => NonBreakingSpaces::apply($this->twig->render($template, MailPreviewService::sPredmetem($data, $predmet))),
        ];
    }

    /**
     * Zdroj celého Twig dokumentu (neuložená kampaň z editoru šablony), nebo null, když jde o tělo zprávy.
     *
     * Rodič z pole „Vychází z" se doplní TÝMŽ {@see TwigTemplate::slozitZdroj()} jako při odeslání,
     * takže náhled i kontrola vidí přesně to, co odejde. Příznak z editoru nestačí sám: na téže obrazovce
     * se upravují i bloky (kind=snippet), a to je tělo zprávy — rozhoduje proto i výsledek (`extends`).
     */
    private static function zdrojDokumentu(ParticipantManualMail $mail): ?string
    {
        if (!$mail->document || null !== $mail->templateSlug) {
            return null;
        }
        $zdroj = TwigTemplate::slozitZdroj($mail->parent, $mail->body);

        return 1 === preg_match('/\{%-?\s*extends\b/', $zdroj) ? $zdroj : null;
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
        $prilohy = $this->prilohy($mail);
        $data['prilohyKeStazeni'] = $prilohy['odkazy'];
        $participantMail = new ParticipantMail($participant, $appUser, $subject, $type);
        $participantMail->setAttachments($prilohy['popis']);
        if (null !== $bulk) {
            $participantMail->setBulk($bulk);
            // Hromadná rozesílka běží po dávkách a kurzor se zapisuje až po odeslání; klíč
            // jedinečnosti je to, co i po pádu uprostřed dávky zaručí jednu zprávu na adresáta.
            $participantMail->setDeliveryKey(DeliveryKey::ofOrNull(
                'bulk',
                $bulk->getId(),
                $participant->getId(),
                $appUser->getId(),
            )?->value);
        }
        $participantMail->setPastMails($this->participantMailRepository->findByParticipant($participant));
        // Ruční zpráva, ne automat → MailerSubscriber nastaví Auto-Submitted: no.
        $participantMail->markAsManual();
        // Záznam, který se skutečně použil (u hromadné rozesílky s klíčem může jít o starší, opakovaný).
        $zaznam = $this->mailService->sendEMail($participantMail, $template, $data, $prilohy['soubory']);

        return $zaznam instanceof ParticipantMail ? $zaznam : $participantMail;
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
