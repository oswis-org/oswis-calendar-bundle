<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantMail;

use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\InverseJoinColumn;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailGroupRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantFilterEvaluator;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMailGroup;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\DateTimeRange;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;

/**
 * @author Jakub Zak <mail@jakubzak.eu>
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['participant_mail_groups_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Post(
            denormalizationContext: ['groups' => ['participant_mail_groups_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Get(
            normalizationContext: ['groups' => ['participant_mail_group_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Put(
            denormalizationContext: ['groups' => ['participant_mail_group_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_ADMIN')"
        ),
    ],
    normalizationContext: ['groups' => ['participant_mail_groups_get'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['participant_mail_groups_post'], 'enable_max_depth' => true],
    filters: ['search'],
    security: "is_granted('ROLE_ADMIN')"
)]
#[SearchAnnotation(['id'])]
#[Entity(repositoryClass: ParticipantMailGroupRepository::class)]
#[Table(name: 'calendar_participant_mail_group')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant_mail')]
class ParticipantMailGroup extends AbstractMailGroup
{
    #[ManyToOne(targetEntity: ParticipantMailCategory::class, fetch: 'EAGER')]
    #[JoinColumn(nullable: true)]
    protected ?ParticipantMailCategory $category = null;

    #[ManyToOne(targetEntity: Event::class, fetch: 'EAGER')]
    #[JoinColumn(nullable: true)]
    protected ?Event $event = null;

    #[Column(type: 'boolean', nullable: false)]
    protected bool $onlyActive = true;

    /**
     * Kategorie přihlášek, kterým skupina smí psát.
     *
     * PROČ to tu je (nalezeno 18. 9. 2026): skupina se dosud omezovala jen akcí, `onlyActive`
     * a filtrem — kategorii přihlášky **neřešilo nic**. Pozor na svůdnou záměnu: pole `category`
     * o kus výš je kategorie MAILU („Ověření přihlášky", „Shrnutí"), ne kategorie přihlášky.
     * Důsledek byl, že jakmile by na ročník přibyla přihláška člena týmu, hosta nebo personálu,
     * spadli by do publika účastnických rozesílek („Než vyrazíš na Seznamovák…").
     *
     * **PRÁZDNÉ = jen kategorie typu `attendee`.** Je to úmyslně bezpečný výchozí stav: všech
     * 28 historických skupin bylo psaných pro účastníky, takže prázdná kolekce zachovává jejich
     * záměr beze změny dat. Zároveň tím z výchozího stavu vypadávají i testovací přihlášky
     * (`attendee-test`), což je správně.
     *
     * Psát týmu jde dál — ale musí to být **vědomý úkon**: v té skupině se kategorie vyberou.
     * Tvrdý zákaz tu schválně NENÍ; tiché zahazování pošty je horší vada než pošta navíc
     * a týmu psát legitimně chceme.
     *
     * Záměrně kategorie, NE typ: „Člen týmu" a „Personál" sdílejí `type = staff`, a to jsou
     * podle zadání dvě různé populace (tým akce × personál kempu).
     *
     * @var Collection<int, ParticipantCategory>
     */
    #[ManyToMany(targetEntity: ParticipantCategory::class, fetch: 'EAGER')]
    #[JoinTable(name: 'calendar_participant_mail_group_participant_category')]
    #[JoinColumn(name: 'mail_group_id', referencedColumnName: 'id')]
    #[InverseJoinColumn(name: 'participant_category_id', referencedColumnName: 'id')]
    protected Collection $participantCategories;

    /**
     * Optional recipient filter — a {@see ParticipantFilterEvaluator} boolean expression
     * (same language as the unified participant list: hasFlag('…'), hasFlagInCategory('fakulta'),
     * isPaid(), eventSlug() …). Null/blank = no extra restriction. Lets a campaign target a segment
     * beyond the event scope (one faculty, one accommodation type, unpaid only, …) and lets a
     * transactional type use a segment-specific template variant (higher priority + filter) with a
     * filterless fallback group catching the rest.
     */
    #[Column(type: 'text', nullable: true)]
    protected ?string $filterExpression = null;

    /** Lazily-built shared evaluator (stateless, no dependencies) for {@see isApplicableByFilter()}. */
    private static ?ParticipantFilterEvaluator $filterEvaluator = null;

    public function __construct(
        ?Nameable $nameable = null,
        ?int $priority = null,
        ?DateTimeRange $range = null,
        ?TwigTemplate $twigTemplate = null,
        bool $automaticMailing = false,
        ?ParticipantMailCategory $participantMailCategory = null
    ) {
        parent::__construct($nameable, $priority, $range, $twigTemplate, $automaticMailing);
        $this->participantCategories = new ArrayCollection();
        $this->setCategory($participantMailCategory);
    }

    /** @return Collection<int, ParticipantCategory> */
    public function getParticipantCategories(): Collection
    {
        return $this->participantCategories;
    }

    public function addParticipantCategory(?ParticipantCategory $category): void
    {
        if (null !== $category && !$this->getParticipantCategories()->contains($category)) {
            $this->getParticipantCategories()->add($category);
        }
    }

    public function removeParticipantCategory(?ParticipantCategory $category): void
    {
        if (null !== $category) {
            $this->getParticipantCategories()->removeElement($category);
        }
    }

    /**
     * Smí tahle skupina psát téhle přihlášce?
     *
     * Prázdná kolekce = jen typ `attendee` (viz {@see $participantCategories}). Vyplněná = přesně
     * vyjmenované kategorie. FAIL-CLOSED: přihláška bez kategorie nedostane nic — radši nikoho
     * neoslovit než oslovit někoho, o kom nevíme, co je zač.
     */
    public function isApplicableByParticipantCategory(Participant $participant): bool
    {
        $category = $participant->getParticipantCategory();
        if (null === $category) {
            return false;
        }
        $allowed = $this->getParticipantCategories();
        if ($allowed->isEmpty()) {
            return ParticipantCategory::TYPE_ATTENDEE === $category->getType();
        }
        foreach ($allowed as $allowedCategory) {
            if ($allowedCategory->getId() === $category->getId()) {
                return true;
            }
        }

        return false;
    }

    public function isCategory(?ParticipantMailCategory $category): bool
    {
        return $this->getCategory() === $category;
    }

    public function getCategory(): ?ParticipantMailCategory
    {
        return $this->category;
    }

    public function setCategory(?ParticipantMailCategory $category): void
    {
        $this->category = $category;
    }

    public function isType(?string $type): bool
    {
        return $this->getType() === $type;
    }

    public function getType(): ?string
    {
        return $this->getCategory()?->getType();
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }

    public function isOnlyActive(): bool
    {
        return $this->onlyActive;
    }

    public function setOnlyActive(bool $onlyActive): void
    {
        $this->onlyActive = $onlyActive;
    }

    public function getFilterExpression(): ?string
    {
        return $this->filterExpression;
    }

    public function setFilterExpression(?string $filterExpression): void
    {
        $filterExpression = null === $filterExpression ? null : trim($filterExpression);
        $this->filterExpression = ('' === $filterExpression) ? null : $filterExpression;
    }

    public function isApplicableByRestrictions(?object $entity): bool
    {
        if (!($entity instanceof Participant)) {
            return false;
        }
        if ($this->onlyActive && !$entity->isActive()) {
            return false;
        }
        if ($this->event && !$entity->isContainedInEvent($this->event)) {
            return false;
        }
        if (!$this->isApplicableByParticipantCategory($entity)) {
            return false;
        }

        return $this->isApplicableByFilter($entity);
    }

    /**
     * Whether the participant matches the optional filter expression. No filter = applicable.
     * FAIL-CLOSED: a broken stored expression must never widen the recipient set, so any evaluation
     * error means "not applicable" (the edit form validates expressions on save, and the automail
     * drain pre-validates and reports per group — this catch is the last line of defense).
     */
    public function isApplicableByFilter(Participant $participant): bool
    {
        $expression = $this->filterExpression;
        if (null === $expression || '' === trim($expression)) {
            return true;
        }
        try {
            return (self::$filterEvaluator ??= new ParticipantFilterEvaluator())->matches($participant, $expression);
        } catch (\Throwable) {
            return false;
        }
    }
}
