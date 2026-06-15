<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagGroupOffer;
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantFlagUpdateService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JWT-secured flag editing for the Ionic admin.
 *
 *  - GET  /api/participants/{id}/available-flags — every flag category selectable for the
 *    participant (recursive, incl. admin-only/non-public ones the participant has no group for
 *    yet), each offer marked selected/full. Lets the mobile admin show ALL categories, not just
 *    the ones already on the participant.
 *  - POST /api/participants/{id}/flags — set one category's flags in a single call.
 *
 * Both delegate to {@see ParticipantFlagUpdateService} — the SAME trusted core the web admin uses,
 * so the mobile and web paths can't diverge. This replaces the old PUT /participant_flag_groups
 * flow, which could neither add a flag (the client sent a flagOffer without its @id IRI, so the API
 * couldn't resolve it) nor add a category the participant had no group for.
 */
#[IsGranted('ROLE_MANAGER')]
final class ParticipantFlagApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantFlagUpdateService $flagUpdateService,
    ) {
    }

    public function available(int $participantId): JsonResponse
    {
        $participant = $this->em->find(Participant::class, $participantId);
        if (!$participant instanceof Participant || $participant->isDeleted()) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Účastník nenalezen.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['categories' => $this->serializeModel($participant)]);
    }

    public function set(Request $request, int $participantId): JsonResponse
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

        $rawGroupOfferId = $payload['flagGroupOffer'] ?? null;
        $groupOfferId = is_numeric($rawGroupOfferId) ? (int) $rawGroupOfferId : 0;
        $groupOffer = $groupOfferId > 0 ? $this->em->find(RegistrationFlagGroupOffer::class, $groupOfferId) : null;
        if (!$groupOffer instanceof RegistrationFlagGroupOffer) {
            return new JsonResponse(['error' => 'bad_category', 'message' => 'Neznámá kategorie příznaků.'], Response::HTTP_BAD_REQUEST);
        }

        $flagOfferIds = [];
        foreach ((array) ($payload['flagOffers'] ?? []) as $rawId) {
            if (is_numeric($rawId)) {
                $flagOfferIds[] = (int) $rawId;
            }
        }
        $admin = (bool) ($payload['admin'] ?? false);

        try {
            $this->flagUpdateService->setFlags($participant, $groupOffer, $flagOfferIds, $admin);
        } catch (FlagCapacityExceededException $e) {
            return new JsonResponse(['error' => 'capacity', 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (FlagOutOfRangeException $e) {
            return new JsonResponse(['error' => 'range', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'failed', 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['categories' => $this->serializeModel($participant)]);
    }

    /**
     * Flatten {@see ParticipantFlagUpdateService::getFlagSelectionModel()} into JSON-safe scalars.
     *
     * @return list<array{flagGroupOffer: int|null, category: string, min: int, max: int|null, hasGroup: bool, flagOffers: list<array{id: int|null, name: string|null, price: int, selected: bool, remaining: int|null, full: bool}>}>
     */
    private function serializeModel(Participant $participant): array
    {
        $out = [];
        foreach ($this->flagUpdateService->getFlagSelectionModel($participant) as $cat) {
            $offers = [];
            foreach ($cat['flagOffers'] as $flagOffer) {
                $offer = $flagOffer['offer'];
                $offers[] = [
                    'id'        => $offer->getId(),
                    'name'      => $offer->getFlag()?->getName() ?? $offer->getName(),
                    'price'     => $offer->getPrice(),
                    'selected'  => $flagOffer['selected'],
                    'remaining' => $flagOffer['remaining'],
                    'full'      => $flagOffer['full'],
                ];
            }
            $out[] = [
                'flagGroupOffer' => $cat['groupOffer']->getId(),
                'category'       => $cat['categoryName'],
                'min'            => $cat['min'],
                'max'            => $cat['max'],
                'hasGroup'       => $cat['hasGroup'],
                'flagOffers'     => $offers,
            ];
        }

        return $out;
    }
}
