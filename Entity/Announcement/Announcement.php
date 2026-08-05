<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Announcement;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use DateTimeInterface;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantGroup;
use OswisOrg\OswisCalendarBundle\Repository\Announcement\AnnouncementRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Příspěvek na nástěnku — vzkaz týmu účastníkům během akce.
 *
 * Rozhodnutí uživatele 2026-08-02: **nástěnku tvoří tým v průběhu akce** (jsou to psané
 * příspěvky, ne archiv odeslaných notifikací) a **u vybraných se navíc pošle push**. Push je
 * tedy PŘÍZNAK příspěvku, ne samostatný kanál — jeden obsah, dvě cesty doručení. Tím odpadá
 * riziko, že se vzkaz pošle a nikde nezůstane.
 *
 * **Cílení** (od nejširšího): celý turnus (`event`) · jedna skupina/páska (`targetGroup`) ·
 * jeden účastník (`participant`). Vyplňuje se vždy `event`; skupina a účastník ho zužují.
 *
 * ⚠️ Samotné odeslání push notifikace tahle entita NEŘEŠÍ — drží jen záměr (`pushRequested`)
 * a stopu (`pushSentAt`). Kanál (VAPID + service worker) je samostatná práce, viz vize B2;
 * do té doby zůstane `pushSentAt` prázdné a příspěvek funguje jako čistá nástěnka.
 */
#[ApiResource(
    operations: [
        // ⚠️ Čtení ZÁMĚRNĚ bez obecné grupy `entities_get`/`entity_get`: ta přitáhne celý
        // nameable balík (slug, description, note, createdBy, updatedBy…) a hlavně rozbalí
        // vnořenou akci i s jejími autory. Nástěnka potřebuje nadpis, text, čas a cílení —
        // nic víc. Stejná třída nafouknutí jako u kolekce akcí (13,8 MB / 55 s).
        new GetCollection(
            normalizationContext: ['groups' => ['calendar_announcements_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['calendar_announcement_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_announcements_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_announcement_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_announcement_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
    order: ['pinnedUntil' => 'DESC', 'publishedAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['event.id'])]
#[Entity(repositoryClass: AnnouncementRepository::class)]
#[Table(name: 'calendar_announcement')]
// Účastnický dotaz se ptá vždy „co je zveřejněné pro můj turnus" — index kryje právě tohle.
#[Index(name: 'announcement_event_published_idx', columns: ['event_id', 'published_at'])]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_announcement')]
class Announcement implements NameableInterface
{
    use NameableTrait;
    use TextValueTrait;
    use DeletedTrait;

    /**
     * Turnus, kterému je vzkaz určen. Nastavuje se SETTEREM, ne konstruktorem — API Platform
     * resolvuje IRI relace jen přes setter ([[reference_apiplatform_relation_iri_on_post]]).
     */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    #[Assert\NotNull]
    protected ?Event $event = null;

    /** Zúžení na jednu skupinu (pásku); null = celý turnus. */
    #[ManyToOne(targetEntity: ParticipantGroup::class)]
    #[JoinColumn(name: 'target_group_id', nullable: true)]
    protected ?ParticipantGroup $targetGroup = null;

    /** Zúžení na jednoho účastníka; null = neadresné. */
    #[ManyToOne(targetEntity: Participant::class)]
    #[JoinColumn(name: 'participant_id', nullable: true)]
    protected ?Participant $participant = null;

    /**
     * Okamžik zveřejnění; null = rozepsané, účastník ho NEVIDÍ.
     * Tým tak může vzkaz připravit dopředu a vydat ho, až bude chtít.
     */
    #[Column(name: 'published_at', type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $publishedAt = null;

    /**
     * Do kdy zůstane připnutý nahoře; null = nepřipnutý.
     * Záměrně DATUM, ne příznak — připnutí se jinak nikdy nezruší a nástěnku ucpe.
     */
    #[Column(name: 'pinned_until', type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $pinnedUntil = null;

    /** Tým si přeje k příspěvku poslat i push. */
    #[Column(name: 'push_requested', type: 'boolean', options: ['default' => false])]
    protected bool $pushRequested = false;

    /** Kdy push reálně odešel; null = neodesláno (nebo kanál ještě neexistuje). */
    #[Column(name: 'push_sent_at', type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $pushSentAt = null;

    public function __construct(?Nameable $nameable = null, ?string $textValue = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setTextValue($textValue);
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }

    public function getTargetGroup(): ?ParticipantGroup
    {
        return $this->targetGroup;
    }

    public function setTargetGroup(?ParticipantGroup $targetGroup): void
    {
        $this->targetGroup = $targetGroup;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): void
    {
        $this->participant = $participant;
    }

    public function getPublishedAt(): ?DateTimeInterface
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?DateTimeInterface $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
    }

    public function getPinnedUntil(): ?DateTimeInterface
    {
        return $this->pinnedUntil;
    }

    public function setPinnedUntil(?DateTimeInterface $pinnedUntil): void
    {
        $this->pinnedUntil = $pinnedUntil;
    }

    public function isPushRequested(): bool
    {
        return $this->pushRequested;
    }

    public function setPushRequested(bool $pushRequested): void
    {
        $this->pushRequested = $pushRequested;
    }

    public function getPushSentAt(): ?DateTimeInterface
    {
        return $this->pushSentAt;
    }

    public function setPushSentAt(?DateTimeInterface $pushSentAt): void
    {
        $this->pushSentAt = $pushSentAt;
    }

    /** Zveřejněný = má datum zveřejnění a to už nastalo. */
    public function isPublished(?DateTimeInterface $now = null): bool
    {
        $now ??= new \DateTime();

        return null !== $this->publishedAt && $this->publishedAt <= $now;
    }

    /** Připnutý = má datum připnutí a to ještě neuplynulo. */
    public function isPinned(?DateTimeInterface $now = null): bool
    {
        $now ??= new \DateTime();

        return null !== $this->pinnedUntil && $this->pinnedUntil > $now;
    }
}
