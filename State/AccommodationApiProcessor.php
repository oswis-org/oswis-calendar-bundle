<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationUnit;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Bed;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\RoommateGroup;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Write processor pro accommodation CRUD resources (AccommodationUnit, Bed, RoommateGroup). API Platform
 * denormalizer neresolvuje IRI relací do managed entit (staví prázdné embedded → Doctrine cascade error) —
 * stejný důvod jako u {@see ProgramApiProcessor}. Resolvujeme relace z IRI v payloadu, nastavíme přes settery,
 * pak delegujeme na default persist processor.
 *
 * @implements ProcessorInterface<object, object>
 */
final class AccommodationApiProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<object, object> $persistProcessor
     */
    public function __construct(
        private readonly ProcessorInterface $persistProcessor,
        private readonly IriConverterInterface $iriConverter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $payload = $this->payload();
        if ([] !== $payload) {
            if ($data instanceof AccommodationUnit) {
                $this->resolveSingle($data, $payload, 'facility', 'setFacility');
                $this->resolveSingle($data, $payload, 'pricingTemplate', 'setPricingTemplate');
                $this->resolveUnitFeatures($data, $payload);
            } elseif ($data instanceof Bed) {
                $this->resolveSingle($data, $payload, 'unit', 'setUnit');
                $this->resolveSingle($data, $payload, 'pairedWith', 'setPairedWith');
            } elseif ($data instanceof RoommateGroup) {
                $this->resolveSingle($data, $payload, 'event', 'setEvent');
                $this->resolveGroupMembers($data, $payload);
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /** @return array<array-key, mixed> */
    private function payload(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return [];
        }
        $content = (string) $request->getContent();
        if ('' === $content) {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function resolveSingle(object $data, array $payload, string $key, string $setter): void
    {
        if (!array_key_exists($key, $payload)) {
            return;
        }
        $value = $payload[$key];
        if (null === $value) {
            $data->$setter(null);

            return;
        }
        $resolved = $this->resolve($value);
        if (null !== $resolved) {
            $data->$setter($resolved);
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function resolveUnitFeatures(AccommodationUnit $unit, array $payload): void
    {
        if (!array_key_exists('features', $payload) || !is_array($payload['features'])) {
            return;
        }
        foreach ($unit->getFeatures()->toArray() as $existing) {
            $unit->removeFeature($existing);
        }
        foreach ($payload['features'] as $iri) {
            $resolved = $this->resolve($iri);
            if ($resolved instanceof \OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationFeature) {
                $unit->addFeature($resolved);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function resolveGroupMembers(RoommateGroup $group, array $payload): void
    {
        if (!array_key_exists('members', $payload) || !is_array($payload['members'])) {
            return;
        }
        foreach ($group->getMembers()->toArray() as $existing) {
            $group->removeMember($existing);
        }
        foreach ($payload['members'] as $iri) {
            $resolved = $this->resolve($iri);
            if ($resolved instanceof Participant) {
                $group->addMember($resolved);
            }
        }
    }

    private function resolve(mixed $value): ?object
    {
        $iri = is_array($value) && isset($value['@id']) ? $value['@id'] : $value;
        if (!is_string($iri) || '' === $iri) {
            return null;
        }
        try {
            return $this->iriConverter->getResourceFromIri($iri);
        } catch (\Throwable) {
            return null;
        }
    }
}
