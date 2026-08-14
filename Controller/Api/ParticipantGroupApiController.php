<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantGroup;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Zařazení účastníka do pásku (skupiny) pro Ionic admin.
 *
 *  - POST /api/participants/{id}/group  tělo { "group": <groupId> | null }
 *
 * PROČ SAMOSTATNÝ ENDPOINT A NE GENERICKÝ PUT: `Participant.group` je v serializaci pouze ve
 * *čtecích* grupách (`calendar_participants_get`, `calendar_participant_get`,
 * `calendar_check_in_queue`) — v žádné `*_put`/`*_post`. Pásek tedy přes PUT nastavit nešlo
 * a **modul neměl ŽÁDNOU zápisovou cestu**: pásek se nedal ani založit (API CRUD existuje, ale
 * nevolalo ho žádné UI), ani nikomu přiřadit. Data se do něj dala dostat jen přímým SQL.
 * (Ověřeno 2026-08-12; na páscích přitom visí řazení check-in listu podle `mealOrder`, tedy
 * provozní pravidlo „dietáři jdou první na jídlo", rotační sloty programu a cílení nástěnky.)
 *
 * Rozšířit zápisovou grupu by nestačilo: API Platform 4 u vnořeného `{group:{id}}` existující
 * entitu neresolvuje a vyrobí prázdnou — přesně kvůli tomu vznikl
 * {@see ParticipantOfferApiController}. Držíme tedy týž vzor: endpoint si pásek dohledá podle id.
 *
 * `null` pásek odebere (účastník bez pásku je legitimní stav — pásky se rozdávají až u příjezdu).
 */
#[IsGranted('ROLE_MANAGER')]
final class ParticipantGroupApiController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function set(Request $request, int $participantId): JsonResponse
    {
        $participant = $this->em->find(Participant::class, $participantId);
        if (!$participant instanceof Participant || $participant->isDeleted()) {
            return new JsonResponse(
                ['error' => 'not_found', 'message' => 'Účastník nenalezen.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($request->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(
                ['error' => 'bad_json', 'message' => 'Neplatné tělo požadavku.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $rawGroupId = $payload['group'] ?? null;
        if (null === $rawGroupId) {
            $participant->setGroup(null);
            $this->em->flush();

            return new JsonResponse(['ok' => true, 'group' => null]);
        }

        $groupId = is_numeric($rawGroupId) ? (int) $rawGroupId : 0;
        $group = $groupId > 0 ? $this->em->find(ParticipantGroup::class, $groupId) : null;
        if (!$group instanceof ParticipantGroup) {
            return new JsonResponse(
                ['error' => 'bad_group', 'message' => 'Neznámý pásek.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Pásek patří turnusu. Zařadit člověka do pásku CIZÍHO turnusu je vždycky chyba obsluhy
        // (na check-in stole běží dva turnusy za sebou ze stejného tabletu) a rozbilo by to řazení
        // na jídlo i tisk seznamu. Povolujeme i pásek na nadřazené akci — sdílené pásky přes oba
        // turnusy jsou legitimní, stejně jako sdílené příznakové skupiny visí na rodiči.
        if (!$this->groupBelongsToParticipantEvent($group, $participant)) {
            return new JsonResponse(
                ['error' => 'event_mismatch', 'message' => 'Pásek patří k jiné akci než přihláška.'],
                Response::HTTP_CONFLICT,
            );
        }

        $participant->setGroup($group);
        $this->em->flush();

        return new JsonResponse([
            'ok'    => true,
            'group' => [
                'id'        => $group->getId(),
                'name'      => $group->getName(),
                'color'     => $group->getColor(),
                'mealOrder' => $group->getMealOrder(),
            ],
        ]);
    }

    /**
     * Pásek smí být na turnusu účastníka nebo na kterékoli nadřazené akci (sdílený pásek).
     */
    private function groupBelongsToParticipantEvent(ParticipantGroup $group, Participant $participant): bool
    {
        $groupEvent = $group->getEvent();
        if (!$groupEvent instanceof Event) {
            return false;
        }
        $groupEventId = $groupEvent->getId();
        $event = $participant->getEvent();
        // Omezená hloubka: strom akcí je ročník → turnus (→ sekce). Konečný počet kroků navíc
        // chrání před zacyklením, kdyby se superEvent někdy nastavil špatně.
        for ($depth = 0; $depth < 5 && $event instanceof Event; $depth++) {
            if ($event->getId() === $groupEventId) {
                return true;
            }
            $event = $event->getSuperEvent();
        }

        return false;
    }
}
