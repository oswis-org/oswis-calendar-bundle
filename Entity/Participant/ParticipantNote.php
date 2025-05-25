<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\DeletedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\TextValueInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;
use Symfony\Component\Serializer\Annotation\MaxDepth;

/**
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "textValue"
 * })
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['calendar_participant_notes_get']],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Post(
            denormalizationContext: ['groups' => ['calendar_participant_notes_post']],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Get(
            normalizationContext: ['groups' => ['calendar_participant_note_get']],
            security: "is_granted('ROLE_MANAGER')"
        ),
        new Put(
            denormalizationContext: ['groups' => ['calendar_participant_note_put']],
            security: "is_granted('ROLE_MANAGER')"
        ),
    ],
    filters: ['search'],
    security: "is_granted('ROLE_MANAGER')"
)]
#[Entity]
#[Table(name: 'calendar_participant_note')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
#[ApiFilter(OrderFilter::class)]
class ParticipantNote implements BasicInterface, TextValueInterface, DeletedInterface
{
    use BasicTrait;
    use TextValueTrait;
    use DeletedTrait;

    #[ManyToOne(targetEntity: Participant::class, inversedBy: 'notes')]
    #[JoinColumn(nullable: true)]
    #[MaxDepth(1)]
    protected ?Participant $participant = null;

    /** Is note public? */
    #[Column(type: 'boolean')]
    protected bool $publicNote = false;

    public function __construct(
        ?Participant $participant = null,
        ?string $textValue = null,
        ?bool $publicNote = false,
    ) {
        $this->setParticipant($participant);
        $this->setTextValue($textValue);
        $this->setPublicNote((bool)$publicNote);
    }

    public function isPublicNote(): bool
    {
        return $this->publicNote;
    }

    public function setPublicNote(?bool $publicNote): void
    {
        $this->publicNote = $publicNote ?? false;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): void
    {
        if ($this->participant === $participant) {
            return;
        }
        $this->participant?->removeNote($this);
        $this->participant = $participant;
        $participant?->addNote($this);
    }
}
