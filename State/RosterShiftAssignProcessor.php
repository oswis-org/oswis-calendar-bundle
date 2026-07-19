<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DateTime;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventStaffAssignment;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\StaffTeam;
use OswisOrg\OswisCalendarBundle\Service\Program\ProgramRosterService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Zápis směny rozpisu služeb: POST /api/program_service_roster.
 *
 * Command-styl (stejný vzor jako {@see ParticipantStationVisitProcessor} u check-inu): klient pošle
 * jednoduchý payload „přiřaď KOHO na JAKOU službu KDY", procesor z něj poskládá volání a deleguje na
 * SDÍLENÉ jádro {@see ProgramRosterService::assignShift()} (totéž používá web editor). Tělo requestu:
 *   {
 *     "turnus": "/api/events/47",          // IRI turnusu (povinné)
 *     "serviceType": "service-steering",   // typ služby (povinné)
 *     "date": "2025-09-08",                // den směny, Y-m-d (povinné)
 *     "start": "06:00", "end": "12:00",    // čas směny, H:i (povinné) — čas, ne půlden
 *     "participant": "/api/participants/9" // NEBO "externalName": "Alča" NEBO "team": "/api/staff_teams/3"
 *     "roleLabel": "hlavní"                // nepovinné
 *   }
 * Command pole (turnus/serviceType/date/start/end) NEJSOU vlastnosti EventStaffAssignment, proto je
 * operace denormalizuje s `allow_extra_attributes` a procesor je čte z raw payloadu (relace přes
 * IriConverter — denormalizer je do managed entit neresolvuje, viz ProgramApiProcessor). Vrací výsledné
 * přiřazení, které se serializuje lean grupou `calendar_service_roster` (shodně se čtením roštu).
 *
 * @implements ProcessorInterface<EventStaffAssignment, EventStaffAssignment>
 */
final class RosterShiftAssignProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly IriConverterInterface $iriConverter,
        private readonly RequestStack $requestStack,
        private readonly ProgramRosterService $rosterService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EventStaffAssignment
    {
        $payload = $this->payload();

        $turnus = $this->resolveIri($payload, 'turnus', Event::class);
        if (!$turnus instanceof Event) {
            throw new BadRequestHttpException('Chybí nebo neplatný turnus (IRI v poli "turnus").');
        }
        $serviceType = $this->stringField($payload, 'serviceType');
        if (null === $serviceType) {
            throw new BadRequestHttpException('Chybí typ služby (pole "serviceType").');
        }
        $date = $this->stringField($payload, 'date');
        $start = $this->parseDateTime($date, $this->stringField($payload, 'start'));
        $end = $this->parseDateTime($date, $this->stringField($payload, 'end'));
        if (null === $start || null === $end) {
            throw new BadRequestHttpException('Chybí nebo neplatný čas směny (pole "date" Y-m-d + "start"/"end" H:i).');
        }

        try {
            return $this->rosterService->assignShift(
                $turnus,
                $serviceType,
                $start,
                $end,
                $this->resolveIri($payload, 'participant', Participant::class),
                $this->resolveIri($payload, 'team', StaffTeam::class),
                $this->stringField($payload, 'externalName'),
                $this->stringField($payload, 'roleLabel'),
            );
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }
    }

    /** @return array<array-key, mixed> */
    private function payload(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return [];
        }
        try {
            $decoded = json_decode($request->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @template T of object
     *
     * @param array<array-key, mixed> $payload
     * @param class-string<T>         $expected
     *
     * @return T|null
     */
    private function resolveIri(array $payload, string $key, string $expected): ?object
    {
        $iri = $payload[$key] ?? null;
        if (!is_string($iri) || '' === $iri) {
            return null;
        }
        try {
            $entity = $this->iriConverter->getResourceFromIri($iri);
        } catch (\Throwable) {
            return null;
        }

        return $entity instanceof $expected ? $entity : null;
    }

    /** @param array<array-key, mixed> $payload */
    private function stringField(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' !== $value ? $value : null;
    }

    private function parseDateTime(?string $date, ?string $time): ?DateTime
    {
        if (null === $date || null === $time) {
            return null;
        }
        $parsed = DateTime::createFromFormat('Y-m-d H:i', "$date $time");

        return false !== $parsed ? $parsed : null;
    }
}
