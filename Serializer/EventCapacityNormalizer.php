<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Serializer;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Participant\SubEventAttendanceRepository;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Decorates the default API Platform JSON-LD item normalizer to inject
 * Event.currentAttendanceCount on calendar_event(s)_get group output.
 *
 * MUST implement SerializerAwareInterface and forward setSerializer() to
 * the decorated inner — otherwise the inner (an API Platform
 * AbstractItemNormalizer subclass) never gets its serializer property set
 * and throws "The injected serializer must be an instance of
 * NormalizerInterface" whenever it tries to normalise a related object.
 *
 * Spec: docs/superpowers/specs/2026-05-22-S2-S3-S4-calendar-ux-2.0-design.md S2 step 4.1.1
 */
final class EventCapacityNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    public function __construct(
        private readonly NormalizerInterface $decorated,
        private readonly SubEventAttendanceRepository $attendanceRepository,
    ) {
    }

    public function setSerializer(SerializerInterface $serializer): void
    {
        if ($this->decorated instanceof SerializerAwareInterface) {
            $this->decorated->setSerializer($serializer);
        }
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $data = $this->decorated->normalize($object, $format, $context);

        if ($object instanceof Event && is_array($data)) {
            $groups = (array) ($context['groups'] ?? []);
            if (in_array('calendar_events_get', $groups, true) || in_array('calendar_event_get', $groups, true)) {
                $data['currentAttendanceCount'] = $this->attendanceRepository->countActiveByEvent($object);
            }
        }

        return $data;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->decorated->supportsNormalization($data, $format, $context);
    }

    /**
     * @return array<string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return $this->decorated->getSupportedTypes($format);
    }
}
