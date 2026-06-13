<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Serializer;

use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Strips internal-only fields from API output for non-manager viewers.
 *
 * A logged-in participant (ROLE_CUSTOMER) reaches only their OWN participant record
 * (row-scoped by {@see \OswisOrg\OswisCalendarBundle\ApiPlatform\ParticipantContainsUserExtension}),
 * but the embedded participant graph still serialized fields meant strictly for organisers:
 *   - any `internalNote` (the OSWIS NameableTrait convention for an organiser-only note) —
 *     e.g. the payment's raw bank-statement import line (counterparty account, payer name,
 *     phone) used for matching, a flag's internal note, a contact-detail internal note;
 *   - {@see ParticipantNote}::textValue of a NON-public note (publicNote = false).
 *
 * Managers (ROLE_MANAGER, incl. ROLE_ADMIN via hierarchy) keep everything — the web/Ionic
 * admin relies on it. Public payment notes (`note`) and public participant notes stay visible
 * to the participant. See feedback_internal_notes_and_disclaimer.
 *
 * Registered as a decorator on BOTH item normalizers (json + json-ld) because the Ionic
 * client requests either format — covering only one would leak via the other. Mirrors the
 * existing {@see EventCapacityNormalizer} pattern, including the SerializerAwareInterface
 * forwarding the inner AbstractItemNormalizer requires.
 */
final class ParticipantPrivacyNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    public function __construct(
        private readonly NormalizerInterface $decorated,
        private readonly Security $security,
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

        // Managers see everything; only strip for non-managers (participants).
        if (!is_array($data) || $this->security->isGranted('ROLE_MANAGER')) {
            return $data;
        }

        // No `internalNote` (NameableTrait convention) ever reaches a participant — on any
        // entity in the graph (payment, flag, contact detail, …). No-op when absent.
        unset($data['internalNote']);

        // A non-public participant note's text is internal too (its publicNote flag stays visible).
        if ($object instanceof ParticipantNote && !$object->isPublicNote()) {
            unset($data['textValue']);
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
