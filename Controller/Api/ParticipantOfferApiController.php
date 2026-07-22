<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JWT-secured turnus (offer) change for the Ionic admin.
 *
 *  - POST /api/participants/{id}/offer  body { "offer": <offerId>, "admin"?: bool }
 *
 * Mirrors {@see ParticipantFlagApiController}: a dedicated endpoint that resolves the REAL
 * RegistrationOffer by id and runs the trusted domain logic (Participant::setOffer → capacity
 * check + flag migration), then the same post-move side-effects the web-admin bulk reassign uses.
 *
 * Why not the generic PUT /participants with {offer:{id}}: `offer` is a virtual property whose
 * setter carries domain logic; API Platform 4 cannot resolve it from an IRI/{id}/@id (it builds an
 * empty offer with capacity 0 → "Kapacita akce byla překročena.") AND setOffer throws during
 * denormalization, before any processor could re-resolve it (verified 2026-07-22). An explicit
 * endpoint sidesteps denormalization entirely. Same spirit as ParticipantPaymentService, which
 * re-resolves an embedded {participant:{id}} to the managed entity by id.
 */
#[IsGranted('ROLE_MANAGER')]
final class ParticipantOfferApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantService $participantService,
    ) {
    }

    public function change(Request $request, int $participantId): JsonResponse
    {
        $participant = $this->em->find(Participant::class, $participantId);
        if (!$participant instanceof Participant || $participant->isDeleted()) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Účastník nenalezen.'], Response::HTTP_NOT_FOUND);
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($request->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'bad_json', 'message' => 'Neplatné tělo požadavku.'], Response::HTTP_BAD_REQUEST);
        }

        $rawOfferId = $payload['offer'] ?? null;
        $offerId = is_numeric($rawOfferId) ? (int) $rawOfferId : 0;
        $offer = $offerId > 0 ? $this->em->find(RegistrationOffer::class, $offerId) : null;
        if (!$offer instanceof RegistrationOffer) {
            return new JsonResponse(['error' => 'bad_offer', 'message' => 'Neznámá nabídka (turnus).'], Response::HTTP_BAD_REQUEST);
        }

        $oldOffer = $participant->getOffer();
        if ($oldOffer === $offer) {
            return new JsonResponse(['ok' => true, 'offer' => ['id' => $offer->getId(), 'name' => $offer->getName()]]);
        }

        // admin=true: ROLE_MANAGER move overrides base capacity (checks FULL capacity), exactly like
        // WebAdminBulkReassignController. A payload "admin": false lets a caller opt into the stricter
        // base-capacity check if ever needed; default true for a manager action.
        $admin = (bool) ($payload['admin'] ?? true);
        try {
            $participant->setOffer($offer, $admin);
            $this->em->persist($participant);
            $this->em->flush();
        } catch (EventCapacityExceededException | FlagCapacityExceededException $e) {
            return new JsonResponse(['error' => 'capacity', 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (FlagOutOfRangeException $e) {
            return new JsonResponse(['error' => 'range', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'failed', 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // Po flush: notifikace o změně přihlášky + přepočet obsazenosti zdrojové i cílové nabídky.
        $this->participantService->applyPostMoveSideEffects($participant, $oldOffer);

        return new JsonResponse(['ok' => true, 'offer' => ['id' => $offer->getId(), 'name' => $offer->getName()]]);
    }
}
