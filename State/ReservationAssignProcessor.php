<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationUnit;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Bed;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Reservation;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Service\Accommodation\AccommodationService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Přiřazení ubytování z check-in stolu (POST {@see Reservation}).
 *
 * Zápis rezervací byl doteď JEN v {@see AccommodationService} a API bylo read-only — stůl tedy neměl
 * jak pokoj přiřadit a zůstával u volného textu. Tenhle processor tu díru zavírá, ale NEobchází
 * službu: veškerá kontrola kapacity a constraintů zůstává v ní (stejný princip jako
 * {@see ParticipantStationVisitProcessor} → {@see \OswisOrg\OswisCalendarBundle\Service\CheckIn\CheckInService}).
 * Mobil i web tak jedou přes totéž jádro a nemůžou se rozejít.
 *
 * Idempotence: `assign()` u účastníka s aktivní rezervací jednotku PŘEPÍŠE (re-assign), nezaloží druhou.
 *
 * Varování ({@see \OswisOrg\OswisCalendarBundle\Service\Accommodation\AccommodationWarning}) jsou MĚKKÁ —
 * neblokují (výjimky jsou u příjezdu běžné: ZTP, páry, kamarádi), ale musí se dostat k obsluze →
 * jedou zpět v serializované rezervaci ({@see Reservation::getWarnings()}).
 *
 * @implements ProcessorInterface<Reservation, Reservation>
 */
final class ReservationAssignProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly IriConverterInterface $iriConverter,
        private readonly RequestStack $requestStack,
        private readonly AccommodationService $accommodationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reservation
    {
        // Relace API Platform denormalizer neresolvuje do managed entit (staví prázdné embedded →
        // cascade error) — čteme je z IRI v payloadu, stejně jako ostatní processory v bundlu.
        $participant = $this->resolveFromPayload('participant', Participant::class) ?? $data->getParticipant();
        $unit = $this->resolveFromPayload('unit', AccommodationUnit::class) ?? $data->getUnit();
        $bed = $this->resolveFromPayload('bed', Bed::class) ?? $data->getBed();

        if (!$participant instanceof Participant) {
            throw new BadRequestHttpException('Chybí nebo neplatný účastník.');
        }
        if (!$unit instanceof AccommodationUnit) {
            throw new BadRequestHttpException('Chybí nebo neplatná ubytovací jednotka.');
        }

        $result = $this->accommodationService->assign(
            $participant,
            $unit,
            $bed instanceof Bed ? $bed : null,
            $data->getFromDate(),
            $data->getToDate(),
        );
        $reservation = $result['reservation'];
        $reservation->setWarnings($result['warnings']);

        return $reservation;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function resolveFromPayload(string $key, string $class): ?object
    {
        $payload = $this->payload();
        $iri = $payload[$key] ?? null;
        if (!is_string($iri) || '' === $iri) {
            return null;
        }
        try {
            $resource = $this->iriConverter->getResourceFromIri($iri);
        } catch (\Throwable) {
            return null;
        }

        return $resource instanceof $class ? $resource : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return [];
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($request->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $decoded;
    }
}
