<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\SubEventAttendance;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\SubEventAttendanceRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Handles POST /api/sub-event-attendances:
 * - Resolves the target Event from the request IRI (API Platform's default denormalizer
 *   builds an empty embedded Event for constructor-arg relations — same gotcha as the
 *   program-module resources; see {@see ProgramApiProcessor}).
 * - Self-signup (default): resolves the current user's Participant.
 * - Staff signup (ROLE_MANAGER + `participant` IRI in body): signs up the SPECIFIED
 *   participant and honours `paid` — the "účastník bez mobilu" case. BC: a request without
 *   `participant` (or a non-manager) always falls back to current-user self-signup and
 *   ignores `paid`.
 * - Enforces capacity inside a transaction (HTTP 409 if full) for the `required` mode.
 *
 * Spec: docs/superpowers/specs/2026-05-22-S2-S3-S4-calendar-ux-2.0-design.md S3 step 4.1.2,
 * docs/superpowers/plans/2026-06-13-program-module-api.md Fáze 4.
 *
 * @implements ProcessorInterface<SubEventAttendance, SubEventAttendance>
 */
final class SubEventAttendanceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ParticipantService $participantService,
        private readonly SubEventAttendanceRepository $attendanceRepository,
        private readonly IriConverterInterface $iriConverter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SubEventAttendance
    {
        $user = $this->security->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Vyžaduje přihlášení.');
        }
        $isManager = $this->security->isGranted('ROLE_MANAGER');
        $payload = $this->payload();

        $event = $this->resolveEvent($payload, $data);
        $signupMode = $event->getSignupMode();

        // Staff signup: a manager may register any participant (sent as IRI) and confirm payment.
        $staffParticipant = null;
        if ($isManager) {
            $staffParticipant = $this->resolveParticipant($payload);
        }

        if ($staffParticipant instanceof Participant) {
            $participant = $staffParticipant;
        } else {
            $participant = $this->currentUserParticipant($user);
        }

        // 'none' → na tuto aktivitu se vůbec nepřihlašuje (informativní / docházka jinde).
        if (Event::SIGNUP_MODE_NONE === $signupMode) {
            throw new AccessDeniedHttpException('Na tuto aktivitu se nelze přihlásit.');
        }
        // 'staff' → self-signup smí jen organizační tým; běžný účastník ne.
        // (Hromadný staff zápis kohokoli = staffParticipant větev výše, taky jen ROLE_MANAGER.)
        if (Event::SIGNUP_MODE_STAFF === $signupMode && !$isManager) {
            throw new AccessDeniedHttpException('Na tuto aktivitu zapisuje organizační tým osobně.');
        }

        $paid = $isManager && $staffParticipant instanceof Participant && isset($payload['paid']) && is_bool($payload['paid'])
            ? $payload['paid']
            : null;

        return $this->em->wrapInTransaction(function () use ($event, $signupMode, $participant, $paid): SubEventAttendance {
            // ⚠️ ZÁMEK ŘÁDKU AKTIVITY — bez něj jsou obě kontroly níž k ničemu.
            //
            // `countActiveByEvent()` i `findActiveForParticipantAndEvent()` jsou prosté SELECTy,
            // po kterých teprve následuje zápis. Dva souběžné požadavky (a to je v appce běžné —
            // dvojí ťuknutí na mobilu pošle dva POSTy) přečtou oba tentýž stav a oba zapíšou:
            // vznikne dvojí přihláška, nebo se překročí kapacita. Samotná transakce proti tomu
            // NEPOMÁHÁ, protože nezamčený SELECT nikoho neblokuje.
            //
            // Přesně takhle vzniklo 16. 8. 2026 102 fiktivních plateb — souběh, který žádná
            // kontrola v aplikaci zachytit nemůže (docs/OSWIS_1_INCIDENT_PAYMENT_DUPLICATES_2026-08-16.md).
            //
            // `PESSIMISTIC_WRITE` na řádku aktivity požadavky na TUTÉŽ aktivitu seřadí za sebe.
            // Zámek drží jen do konce téhle transakce (jednotky ms) a týká se vždy jediného
            // řádku, takže přihlašování na různé aktivity běží dál paralelně.
            //
            // Kapacitu, na rozdíl od dvojího přihlášení, unikátní index ohlídat neumí — je to
            // podmínka nad POČTEM řádků, ne nad jejich hodnotou. Proto zámek, ne constraint.
            $this->em->lock($event, LockMode::PESSIMISTIC_WRITE);

            // Kapacitu vynucujeme všude, kde se účastník hlásí SÁM — tedy u 'required' i 'optional'.
            //
            // ⚠️ Dřív se vynucovala jen u 'required', což se rozcházelo s tím, co portál ukazuje:
            // `event-card` i `program-osa` počítají „plno" z `fullCapacity` bez ohledu na režim,
            // takže účastník viděl „10/10" a zablokované tlačítko, ale POST by prošel — stačilo
            // dvojí ťuknutí nebo souběh a aktivita se přeplnila. Typický případ je lukostřelba:
            // přihlášení nepovinné (= 'optional'), ale luků je deset. Vyplněná kapacita je záměr
            // toho, kdo ji zadal; režim říká, zda se účastník hlásit MUSÍ, ne zda platí strop.
            //
            // 'staff' zůstává bez vynucení: tam zapisuje tým osobně a smí i nad limit.
            // 'none' se sem nedostane, odmítá se výš.
            if (Event::SIGNUP_MODE_REQUIRED === $signupMode || Event::SIGNUP_MODE_OPTIONAL === $signupMode) {
                // Nula = bez stropu, ne „nula míst". Sloupec je nullable, ale `CapacityTrait`
                // prázdnou hodnotu místy převádí na 0, a stejně ji čte i portál
                // (`capacityColor()` bere 0 jako neurčeno). Bez `> 0` by aktivita s nulou
                // odmítla úplně každého hláškou „Aktivita je plná" — `0 >= 0` platí vždy.
                $full = $event->getFullCapacity();
                if (null !== $full && $full > 0) {
                    $count = $this->attendanceRepository->countActiveByEvent($event);
                    if ($count >= $full) {
                        throw new ConflictHttpException('Aktivita je plná.');
                    }
                }
            }
            $existing = $this->attendanceRepository->findActiveForParticipantAndEvent($participant, $event);
            if (null !== $existing) {
                if (null !== $paid) {
                    $existing->setPaid($paid);
                    $this->em->flush();
                }

                return $existing;
            }
            $attendance = new SubEventAttendance($participant, $event);
            if (null !== $paid) {
                $attendance->setPaid($paid);
            }
            $this->em->persist($attendance);
            $this->em->flush();

            return $attendance;
        });
    }

    private function resolveEvent(array $payload, mixed $data): Event
    {
        $iri = $this->iri($payload['event'] ?? null);
        if (null !== $iri) {
            try {
                $resolved = $this->iriConverter->getResourceFromIri($iri);
            } catch (\Throwable $e) {
                throw new BadRequestHttpException('Neplatné IRI aktivity (event).', $e);
            }
            if ($resolved instanceof Event) {
                return $resolved;
            }
        }
        // Fallback: whatever the denormalizer built (BC for clients that somehow resolve it).
        if ($data instanceof SubEventAttendance) {
            $fromData = $data->getEvent();
            if (null !== $fromData && null !== $fromData->getId()) {
                return $fromData;
            }
        }
        throw new BadRequestHttpException('Chybí platná aktivita (event).');
    }

    private function resolveParticipant(array $payload): ?Participant
    {
        $iri = $this->iri($payload['participant'] ?? null);
        if (null === $iri) {
            return null;
        }
        try {
            $resolved = $this->iriConverter->getResourceFromIri($iri);
        } catch (\Throwable $e) {
            throw new BadRequestHttpException('Neplatné IRI účastníka (participant).', $e);
        }

        return $resolved instanceof Participant ? $resolved : null;
    }

    private function currentUserParticipant(AppUser $user): Participant
    {
        $participants = $this->participantService->getParticipants(
            [ParticipantRepository::CRITERIA_APP_USER => $user]
        );
        foreach ($participants as $p) {
            return $p;
        }
        throw new AccessDeniedHttpException('Účet nemá aktivní registraci na ročník.');
    }

    /**
     * @return array<array-key, mixed>
     */
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

    private function iri(mixed $value): ?string
    {
        if (is_string($value) && '' !== $value) {
            return $value;
        }
        if (is_array($value) && isset($value['@id']) && is_string($value['@id'])) {
            return $value['@id'];
        }

        return null;
    }
}
