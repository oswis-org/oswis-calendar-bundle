<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Push;

use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use OswisOrg\OswisCalendarBundle\Entity\Announcement\Announcement;
use OswisOrg\OswisCalendarBundle\Entity\Push\PushSubscription;
use OswisOrg\OswisCalendarBundle\Repository\Push\PushSubscriptionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Odeslání push notifikací účastníkům.
 *
 * PROČ TO VZNIKLO: `Announcement` měl od začátku pole `pushRequested` a `pushSentAt`, ale
 * **nikdo je nečetl** — tým mohl u vzkazu zaškrtnout „poslat push" a nestalo se vůbec nic
 * (ověřeno 19. 8. 2026). Tohle je chybějící doručovací kanál.
 *
 * Rozhodnutí ve vizi (B2): **Web Push s VAPID přes service worker, žádní démoni** — odesílá se
 * v rámci requestu/cronu, ne z běžícího procesu navíc. iOS je vědomě degradované (Web Push
 * až od 16.4 a jen po „přidat na plochu").
 *
 * ⚠️ **Bez klíčů se nic neděje a je to v pořádku.** Když nejsou nastavené VAPID klíče,
 * služba se tiše vypne a jen to zaloguje — appka i nástěnka musí fungovat dál. Nesmí to
 * shodit uložení vzkazu jen proto, že push není nakonfigurovaný.
 */
class PushNotificationService
{
    private readonly string $vapidPublicKey;

    private readonly string $vapidPrivateKey;

    private readonly string $vapidSubject;

    /**
     * ⚠️ Klíče jsou `?string`, protože nenastavená proměnná prostředí přijde jako **null**,
     * ne jako prázdný řetězec (`%env(default::…)%`). S typem `string` kontejner spadl už při
     * sestavení služby — tedy dřív, než se vůbec dalo zjistit, že push není nakonfigurovaný.
     */
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        ?string $vapidPublicKey = null,
        ?string $vapidPrivateKey = null,
        ?string $vapidSubject = null,
    ) {
        $this->vapidPublicKey = trim($vapidPublicKey ?? '');
        $this->vapidPrivateKey = trim($vapidPrivateKey ?? '');
        $this->vapidSubject = trim($vapidSubject ?? '');
    }

    public function jeNastaveno(): bool
    {
        return '' !== $this->vapidPublicKey && '' !== $this->vapidPrivateKey;
    }

    public function verejnyKlic(): string
    {
        return $this->vapidPublicKey;
    }

    /**
     * Rozešle vzkaz z nástěnky lidem, kterým je určený.
     *
     * ⚠️ Doručuje se JEN zveřejněný vzkaz s vyžádaným pushem, a jen jednou — druhé volání už
     * nic neudělá, protože `pushSentAt` je vyplněné. Bez toho by opakované uložení vzkazu
     * (nebo cron) poslalo lidem tutéž zprávu několikrát.
     *
     * @return array{sent: int, failed: int, expired: int, skipped: bool}
     */
    public function odesliVzkaz(Announcement $vzkaz): array
    {
        $vysledek = ['sent' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => true];
        if (!$vzkaz->isPushRequested() || null !== $vzkaz->getPushSentAt() || null === $vzkaz->getPublishedAt()) {
            return $vysledek;
        }
        if (!$this->jeNastaveno()) {
            $this->logger->warning('Push se neodeslal: nejsou nastavené VAPID klíče.');

            return $vysledek;
        }
        $eventId = $vzkaz->getEvent()?->getId();
        if (null === $eventId) {
            return $vysledek;
        }
        $odberatele = $this->subscriptions->proCil(
            $eventId,
            $vzkaz->getTargetGroup()?->getId(),
            $vzkaz->getParticipant()?->getId(),
        );
        $vysledek['skipped'] = false;
        if ([] === $odberatele) {
            // Není komu poslat — vzkaz se přesto označí za odeslaný, ať to cron nezkouší donekonečna.
            //
            // ⚠️ Do 26. 8. 2026 se tahle větev míjela BEZ JEDINÉHO ZÁZNAMU. Tým pak v datech
            // viděl vyplněný `pushSentAt` a mohl si myslet, že notifikace odešla — přitom ji
            // nedostal nikdo. Je to tentýž vzorec jako u tichého selhání SMTP
            // (`reference_smtp_failure_is_silent`): „nespadlo to" není totéž co „došlo to".
            $this->logger->warning(sprintf(
                'Vzkaz #%s: push nedostal NIKDO — pro cíl (akce %s, páska %s, účastník %s) není'
                .' žádný odběr. Vzkaz je na nástěnce, ale upozornění nikomu nedorazilo.',
                $vzkaz->getId() ?? '?',
                $eventId,
                $vzkaz->getTargetGroup()?->getId() ?? '—',
                $vzkaz->getParticipant()?->getId() ?? '—',
            ));
            $vzkaz->setPushSentAt(new \DateTimeImmutable());
            // Nula se zapisuje ZÁMĚRNĚ, ne se nechává null: `null` znamená „ještě se neodesílalo",
            // kdežto `0` znamená „odesílalo se a nedostal to nikdo". Bez toho rozdílu vypadá
            // marný pokus stejně jako neodeslaný vzkaz a tým nemá podle čeho poznat, že je zle.
            $vzkaz->setPushRecipients(0);
            $this->em->flush();

            return $vysledek;
        }

        // ⚠️ Tvar je dán Angularem: service worker (`ngsw`) zobrazí notifikaci SÁM jen tehdy,
        // když má zpráva kořenový klíč `notification`. Při jiném tvaru ji jen předá aplikaci —
        // a když appka zrovna neběží (což je u push ten hlavní případ), člověk neuvidí nic.
        $obsah = json_encode([
            'notification' => [
                'title' => $vzkaz->getName() ?? 'Seznamovák',
                'body'  => mb_substr(strip_tags((string) $vzkaz->getTextValue()), 0, 180),
                'icon'  => '/assets/icons/android-chrome-192x192.png',
                'badge' => '/assets/icons/android-chrome-96x96.png',
                // Kliknutí na notifikaci otevře nástěnku, ne holou domovskou obrazovku.
                'data'  => ['onActionClick' => [
                    'default' => ['operation' => 'navigateLastFocusedOrOpen', 'url' => '/portal/overview/nastenka'],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $vysledek = array_merge($vysledek, $this->rozesli($odberatele, $obsah));
        $vzkaz->setPushSentAt(new \DateTimeImmutable());
        // Počítá se DORUČENO, ne „kolik jsme jich zkusili". Zrušené odběry a selhání jdou mimo,
        // jinak by číslo tvrdilo víc, než kolik lidí upozornění opravdu vidělo.
        $vzkaz->setPushRecipients($vysledek['sent']);
        $this->em->flush();
        $this->logger->info(sprintf(
            'Push k vzkazu #%s: odesláno %d, selhalo %d, zrušených odběrů %d.',
            (string) $vzkaz->getId(),
            $vysledek['sent'],
            $vysledek['failed'],
            $vysledek['expired'],
        ));

        return $vysledek;
    }

    /**
     * @param list<PushSubscription> $odberatele
     *
     * @return array{sent: int, failed: int, expired: int}
     */
    private function rozesli(array $odberatele, string $obsah): array
    {
        // ⚠️ HTTP klient a továrny se předávají NATVRDO, ne přes automatické hledání knihovny.
        // `php-http/discovery` v tomhle projektu žádného kandidáta nenajde (chybí PSR-17
        // implementace) a `new WebPush(...)` pak spadne hned v konstruktoru — ověřeno 19. 8. 2026.
        // Symfony vlastní PSR-18 klienta i továrny má, takže není důvod cokoli dohledávat.
        $psr18 = new Psr18Client($this->httpClient);
        $webPush = new WebPush(
            ['VAPID' => [
                'subject'    => '' !== $this->vapidSubject ? $this->vapidSubject : 'mailto:info@seznamovakup.cz',
                'publicKey'  => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey,
            ]],
            [],
            $psr18,
            $psr18,
            $psr18,
        );
        foreach ($odberatele as $odber) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $odber->getEndpoint(),
                        'keys'     => $odber->keys(),
                    ]),
                    $obsah,
                );
            } catch (Throwable $e) {
                $this->logger->error('Push: odběr se nepodařilo zařadit: '.$e->getMessage());
            }
        }

        $pocty = ['sent' => 0, 'failed' => 0, 'expired' => 0];
        foreach ($webPush->flush() as $zprava) {
            // Knihovna vrací netypovaný generátor; kontrola typu není jen kvůli analyzátoru —
            // kdyby přišlo něco jiného, spadlo by to až za běhu při odesílání vzkazu týmu.
            if (!$zprava instanceof MessageSentReport) {
                $pocty['failed']++;

                continue;
            }
            if ($zprava->isSuccess()) {
                $pocty['sent']++;

                continue;
            }
            // ⚠️ Prohlížeč odběr ruší TIŠE (vyčištěná data, odinstalovaná PWA). Server to pozná
            // jen tady — a mrtvý odběr se musí smazat, jinak se na něj bude marně posílat navždy
            // a počty „selhalo" přestanou znamenat skutečný problém.
            if ($zprava->isSubscriptionExpired()) {
                $pocty['expired']++;
                $mrtvy = $this->subscriptions->najdiPodleEndpointu($zprava->getEndpoint());
                if (null !== $mrtvy) {
                    $this->em->remove($mrtvy);
                }

                continue;
            }
            $pocty['failed']++;
            $this->logger->warning('Push se nedoručil: '.$zprava->getReason());
        }
        $this->em->flush();

        return $pocty;
    }
}
