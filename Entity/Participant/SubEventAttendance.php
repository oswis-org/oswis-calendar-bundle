<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Participant\SubEventAttendanceRepository;
use OswisOrg\OswisCalendarBundle\State\SubEventAttendanceProcessor;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Mapping\ClassMetadata;

/**
 * Records that a Participant signed up for a specific sub-activity Event
 * (LECTURE/WORKSHOP/SPORT/...) within the year/batch they're registered for.
 *
 * Spec: docs/superpowers/specs/2026-05-22-S2-S3-S4-calendar-ux-2.0-design.md
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_CUSTOMER')",
            normalizationContext: ['groups' => ['entities_get', 'calendar_sub_event_attendances_get']],
        ),
        new Get(
            security: "is_granted('ROLE_CUSTOMER')",
            normalizationContext: ['groups' => ['entity_get', 'calendar_sub_event_attendance_get']],
        ),
        new Post(
            security: "is_granted('ROLE_CUSTOMER')",
            denormalizationContext: ['groups' => ['entities_post', 'calendar_sub_event_attendances_post']],
            processor: SubEventAttendanceProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_CUSTOMER')",
        ),
    ],
)]
#[Entity(repositoryClass: SubEventAttendanceRepository::class)]
#[Table(name: 'calendar_sub_event_attendance')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class SubEventAttendance implements BasicInterface
{
    use BasicTrait;
    use DeletedTrait;

    public const STATUS_REGISTERED = 'registered';
    public const STATUS_CANCELED   = 'canceled';

    public const ALLOWED_STATUSES = [self::STATUS_REGISTERED, self::STATUS_CANCELED];

    #[ManyToOne(targetEntity: Participant::class, inversedBy: 'subEventAttendances')]
    #[JoinColumn(nullable: false)]
    protected Participant $participant;

    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(nullable: false)]
    protected Event $event;

    #[Column(type: 'string', length: 32)]
    #[Assert\Choice(choices: self::ALLOWED_STATUSES)]
    protected string $status = self::STATUS_REGISTERED;

    #[Column(type: 'datetime', nullable: false)]
    protected DateTimeInterface $registeredAt;

    public function __construct(Participant $participant, Event $event)
    {
        $this->participant = $participant;
        $this->event = $event;
        $this->registeredAt = new DateTime();
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addConstraint(new Assert\Callback('validateEventIsSubActivity'));
    }

    public function validateEventIsSubActivity(ExecutionContextInterface $context): void
    {
        $type = $this->event->getCategory()?->getType();
        if (in_array($type, ['year-of-event', 'batch-of-event'], true)) {
            $context->buildViolation('SubEventAttendance.event must be a sub-activity, not a year or batch event.')
                ->atPath('event')
                ->addViolation();
        }
    }

    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRegisteredAt(): DateTimeInterface
    {
        return $this->registeredAt;
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELED;
        $this->setDeletedAt(new DateTime());
    }

    public function isActive(): bool
    {
        return self::STATUS_REGISTERED === $this->status && null === $this->getDeletedAt();
    }
}
