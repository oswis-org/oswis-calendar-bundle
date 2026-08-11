<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use OswisOrg\OswisCalendarBundle\Entity\Announcement\Announcement;
use OswisOrg\OswisCalendarBundle\Entity\Meal\MealVariant;
use OswisOrg\OswisCalendarBundle\Entity\Meal\ParticipantMealChoice;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\StaffTeam;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Write processor for the program-module CRUD resources (ProgramDay, EventSection,
 * ParticipantGroup, StaffTeam) and for nástěnka (Announcement).
 *
 * API Platform's default denormalizer does NOT resolve relation IRIs into managed
 * entities for these resources — it builds empty embedded entities, which then trip a
 * Doctrine "new entity found through relationship" cascade error on flush. This processor
 * re-reads the relation IRIs from the request payload, resolves them via the IriConverter
 * into managed entities, sets them through the setters, then delegates to the default
 * Doctrine persist processor. Mirror of the established {@see SubEventAttendanceProcessor}
 * pattern for required relations.
 *
 * @implements ProcessorInterface<object, object>
 */
final class ProgramApiProcessor implements ProcessorInterface
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
        $this->resolveRelations($data);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Dosadí do entity relace z IRI v těle požadavku — BEZ ukládání.
     *
     * ⚠️ Oddělené od `process()` schválně: procesor, který chce po dosazení relací ještě něco
     * ověřit (vlastnictví, uzávěrku), musí kontrolovat PŘED uložením. Kdyby volal `process()`,
     * entita by už byla zapsaná a výjimka by přišla pozdě — v databázi by zůstal zápis, který
     * se měl odmítnout. Používá to {@see MealChoiceProcessor}.
     */
    public function resolveRelations(mixed $data): void
    {
        if (!is_object($data)) {
            return;
        }
        $payload = $this->payload();
        if ([] !== $payload) {
            if ($data instanceof Event) {
                // Event itself (POST/PUT) — its relations hit the same denormalizer gotcha.
                $this->resolveSingle($data, $payload, 'group', 'setGroup');
                $this->resolveSingle($data, $payload, 'category', 'setCategory');
                $this->resolveSingle($data, $payload, 'place', 'setPlace');
                $this->resolveSingle($data, $payload, 'superEvent', 'setSuperEvent');
                $this->resolveSingle($data, $payload, 'targetGroup', 'setTargetGroup');
            } else {
                // Every program resource carries the per-turnus `event` relation.
                $this->resolveSingle($data, $payload, 'event', 'setEvent');

                if ($data instanceof StaffTeam) {
                    $this->resolveMembers($data, $payload);
                }
                if ($data instanceof Announcement) {
                    // Zúžení vzkazu na pásku / jednoho účastníka — tytéž relace, tatáž past.
                    $this->resolveSingle($data, $payload, 'targetGroup', 'setTargetGroup');
                    $this->resolveSingle($data, $payload, 'participant', 'setParticipant');
                }
                if ($data instanceof MealVariant) {
                    // Varianta nevisí na turnusu, ale na jídle — jinak tatáž past.
                    $this->resolveSingle($data, $payload, 'meal', 'setMeal');
                }
                if ($data instanceof ParticipantMealChoice) {
                    // Pořadí je podstatné: `setVariant()` si z varianty dopočítá jídlo, takže
                    // se varianta musí dosadit DŘÍV než případné explicitní `meal` z těla.
                    $this->resolveSingle($data, $payload, 'participant', 'setParticipant');
                    $this->resolveSingle($data, $payload, 'variant', 'setVariant');
                    $this->resolveSingle($data, $payload, 'meal', 'setMeal');
                }
            }
        }
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

    /**
     * @param array<array-key, mixed> $payload
     */
    private function resolveSingle(object $data, array $payload, string $key, string $setter): void
    {
        if (!array_key_exists($key, $payload)) {
            return;
        }
        // ⚠️ Čteme SYROVÉ tělo požadavku, ne denormalizovaná data — takže se sem dostane i klíč,
        // který cílová entita vůbec nemá (`event` poslaný na MealVariant). Bez téhle pojistky
        // by to skončilo fatální chybou „Call to undefined method", tzn. 500 z pouhého překlepu
        // v požadavku. Neznámý klíč prostě ignorujeme — serializační grupy jsou autorita.
        if (!method_exists($data, $setter)) {
            return;
        }
        $value = $payload[$key];
        if (null === $value) {
            $data->$setter(null);

            return;
        }
        $data->$setter($this->resolve($key, $value));
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function resolveMembers(StaffTeam $team, array $payload): void
    {
        if (!array_key_exists('members', $payload)) {
            return;
        }
        $members = $payload['members'];
        if (!is_array($members)) {
            return;
        }
        foreach ($team->getMembers()->toArray() as $existing) {
            $team->removeMember($existing);
        }
        foreach ($members as $member) {
            $resolved = $this->resolve('members', $member);
            if ($resolved instanceof Participant) {
                $team->addMember($resolved);
            }
        }
    }

    private function resolve(string $key, mixed $value): object
    {
        $iri = $this->iri($value);
        if (null === $iri) {
            throw new BadRequestHttpException(sprintf('Pole "%s" musí být IRI odkaz.', $key));
        }
        try {
            return $this->iriConverter->getResourceFromIri($iri);
        } catch (\Throwable $e) {
            // ⚠️ Důvod PATŘÍ do hlášky. Původně tu byla jen věta „Neplatné IRI" a skutečná
            // příčina se zahazovala do zřetězené výjimky, kterou v produkci nikdo neuvidí
            // (`fingers_crossed` reaguje až na ERROR, tohle je 400). Stálo to zbytečné kolo
            // hledání: „neplatné IRI pro meal" ve skutečnosti znamenalo NEZAREGISTROVANÝ
            // repozitář. Routa je jen pro ROLE_MANAGER, takže se tím nic nevyzrazuje ven.
            throw new BadRequestHttpException(
                sprintf('Neplatné IRI pro "%s": %s (%s)', $key, $iri, $e->getMessage()),
                $e,
            );
        }
    }

    private function iri(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value) && isset($value['@id']) && is_string($value['@id'])) {
            return $value['@id'];
        }

        return null;
    }
}
