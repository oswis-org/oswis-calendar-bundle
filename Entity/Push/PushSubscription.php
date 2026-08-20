<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Push;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use OswisOrg\OswisCalendarBundle\Repository\Push\PushSubscriptionRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;

/**
 * Odběr push notifikací — jedno zařízení (prohlížeč) jednoho uživatele.
 *
 * Proč to vzniká: `Announcement` má od začátku pole `pushRequested` a `pushSentAt`, ale
 * **nikdo je nečetl** — tým mohl u vzkazu zaškrtnout „poslat push" a nestalo se nic
 * (ověřeno 19. 8. 2026). Tohle je chybějící druhá polovina: kam se ta zpráva má doručit.
 *
 * Rozhodnutí ve vizi (B2): kanál je **Web Push s VAPID přes service worker**, žádní démoni.
 * iOS je vědomě degradované — Web Push tam funguje až od 16.4 a jen po „přidat na plochu".
 *
 * ⚠️ **Klíč je `endpoint`, ne uživatel.** Jeden člověk má běžně víc zařízení (mobil, notebook)
 * a naopak jedno zařízení může po odhlášení a přihlášení patřit někomu jinému. Endpoint je to,
 * co prohlížeč vydává jako adresu schránky, a je unikátní — proto na něm je i unikátní index:
 * bez něj by opakovaná registrace téhož zařízení nasypala duplicity a člověk by dostal
 * tutéž zprávu několikrát.
 */
#[Entity(repositoryClass: PushSubscriptionRepository::class)]
#[Table(name: 'calendar_push_subscription')]
#[UniqueConstraint(name: 'push_subscription_endpoint_uniq', columns: ['endpoint_hash'])]
#[Index(name: 'push_subscription_user_idx', columns: ['app_user_id'])]
class PushSubscription
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    protected ?int $id = null;

    /**
     * Adresa schránky prohlížeče. Bývá dlouhá (přes 200 znaků), proto je v samostatném
     * TEXT sloupci a unikátnost hlídá až `endpointHash` — MySQL neumí unikátní index
     * nad TEXTem bez délky a ořezaná délka by dvě různé adresy mohla považovat za stejné.
     */
    #[Column(type: 'text')]
    protected string $endpoint;

    /** SHA-256 z `endpoint` — jen kvůli unikátnímu indexu (viz komentář výš). */
    #[Column(name: 'endpoint_hash', type: 'string', length: 64)]
    protected string $endpointHash;

    /** Veřejný klíč zařízení pro šifrování obsahu. */
    #[Column(name: 'p256dh', type: 'string', length: 255)]
    protected string $p256dh;

    /** Autentizační tajemství zařízení. */
    #[Column(name: 'auth_token', type: 'string', length: 255)]
    protected string $authToken;

    #[ManyToOne(targetEntity: AppUser::class)]
    #[JoinColumn(name: 'app_user_id', nullable: false, onDelete: 'CASCADE')]
    protected AppUser $appUser;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    protected DateTimeInterface $createdAt;

    /**
     * Kdy se odběr naposledy ozval. Slouží k úklidu: prohlížeč odběr ruší tiše (vyčištěná
     * data, odinstalovaná PWA) a mrtvé odběry by jinak zůstaly navždy.
     */
    #[Column(name: 'last_seen_at', type: 'datetime_immutable')]
    protected DateTimeInterface $lastSeenAt;

    public function __construct(AppUser $appUser, string $endpoint, string $p256dh, string $authToken)
    {
        $this->appUser = $appUser;
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint);
        $this->p256dh = $p256dh;
        $this->authToken = $authToken;
        $this->createdAt = new DateTimeImmutable();
        $this->lastSeenAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function getAuthToken(): string
    {
        return $this->authToken;
    }

    public function getAppUser(): AppUser
    {
        return $this->appUser;
    }

    /** Zařízení se ozvalo — posouvá se čas pro úklid mrtvých odběrů. */
    public function videnoTed(): void
    {
        $this->lastSeenAt = new DateTimeImmutable();
    }

    /**
     * Přepíše odběr na jiného uživatele téhož zařízení.
     *
     * ⚠️ Bez tohohle by po odhlášení a přihlášení někoho jiného na sdíleném zařízení chodily
     * novému člověku vzkazy toho předchozího — odběr je vázaný na adresu schránky prohlížeče,
     * ne na účet.
     */
    public function prepisNa(AppUser $appUser, string $p256dh, string $authToken): void
    {
        $this->appUser = $appUser;
        $this->p256dh = $p256dh;
        $this->authToken = $authToken;
        $this->videnoTed();
    }

    /** Klíče v podobě, jakou očekává knihovna pro odeslání. */
    public function keys(): array
    {
        return ['p256dh' => $this->p256dh, 'auth' => $this->authToken];
    }
}
