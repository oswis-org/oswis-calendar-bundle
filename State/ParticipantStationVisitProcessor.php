<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use OswisOrg\OswisCalendarBundle\Entity\CheckIn\CheckInStation;
use OswisOrg\OswisCalendarBundle\Entity\CheckIn\ParticipantStationVisit;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Service\CheckIn\CheckInService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Write processor pro {@see ParticipantStationVisit} — IDEMPOTENTNÍ upsert splnění check-in stanice
 * (spec §8/§9). POST i PUT = upsert per (participant, station): opakovaný zápis (offline replay)
 * neupadne na unique constraint, jen aktualizuje (poslední-zápis-vyhrává). DELETE = standardní remove.
 *
 * Relace (participant, station) API Platform denormalizer neresolvuje do managed entit
 * (staví prázdné embedded → cascade error), stejně jako u {@see ProgramApiProcessor} — proto je
 * resolvujeme z IRI v payloadu a předáme {@see CheckInService::recordStationVisit()} (SAME jádro, co
 * použije web admin). Vrací výsledný managed visit, který API Platform serializuje.
 *
 * @implements ProcessorInterface<ParticipantStationVisit, ParticipantStationVisit|null>
 */
final class ParticipantStationVisitProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $removeProcessor
     */
    public function __construct(
        private readonly ProcessorInterface $removeProcessor,
        private readonly IriConverterInterface $iriConverter,
        private readonly RequestStack $requestStack,
        private readonly CheckInService $checkInService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ParticipantStationVisit
    {
        if ($operation instanceof DeleteOperationInterface) {
            $this->removeProcessor->process($data, $operation, $uriVariables, $context);

            return null;
        }

        // $data je denormalizovaný ParticipantStationVisit (skaláry value/note/completedAt sedí);
        // relace participant/station denormalizer neresolvuje → čteme je z IRI v payloadu.
        $participant = $this->resolveFromPayload('participant', Participant::class) ?? $data->getParticipant();
        $station = $this->resolveFromPayload('station', CheckInStation::class) ?? $data->getStation();
        if (!$participant instanceof Participant) {
            throw new BadRequestHttpException('Chybí nebo neplatný účastník.');
        }
        if (!$station instanceof CheckInStation) {
            throw new BadRequestHttpException('Chybí nebo neplatná stanice.');
        }
        if ($station->getEvent()?->getId() !== $participant->getEvent()?->getId()) {
            throw new UnprocessableEntityHttpException('Stanice patří k jinému turnusu než účastník.');
        }

        return $this->checkInService->recordStationVisit(
            $participant,
            $station,
            $data->getValue(),
            $data->getNote(),
            $data->getCompletedAt(),
        );
    }

    /**
     * Resolvne relaci z IRI v request payloadu (denormalizer ji do managed entity neresolvuje).
     *
     * @template T of object
     *
     * @param class-string<T> $expected
     *
     * @return T|null
     */
    private function resolveFromPayload(string $key, string $expected): ?object
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($request->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
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
}
