<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantGroup;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /api/events/{id}/duplicate — clones a single program slot (activity/block) for the
 * editor's "duplicate" button. Copies the descriptive + signup + capacity + relation fields
 * but NOT the children (subEvents, attendances, staff assignments, images/files/contents).
 *
 * Request body (all optional) overrides the clone: startDateTime, endDateTime (ISO 8601),
 * name, targetGroup (IRI), superEvent (IRI). Defaults fall back to the source.
 *
 * @implements ProcessorInterface<mixed, Event>
 */
final class EventDuplicateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $eventRepository,
        private readonly IriConverterInterface $iriConverter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Event
    {
        $id = $uriVariables['id'] ?? null;
        $source = null === $id ? null : $this->eventRepository->find($id);
        if (!$source instanceof Event) {
            throw new NotFoundHttpException('Zdrojová událost nenalezena.');
        }
        $payload = $this->payload();

        $clone = new Event();
        $clone->setName($this->str($payload['name'] ?? null) ?? $source->getName());
        $clone->setShortName($source->getShortName());
        $clone->setDescription($source->getDescription());
        $clone->setColor($source->getColor());
        $clone->setCategory($this->resolve($payload, 'category', EventCategory::class) ?? $source->getCategory());
        $clone->setPlace($source->getPlace(false));
        $clone->setPlaceText($source->getPlaceText());
        $clone->setSignupMode($source->getSignupMode());
        $clone->setSignupNote($source->getSignupNote());
        $clone->setSignupDeadline($source->getSignupDeadline());
        $clone->setPrice($source->getPrice());
        $clone->setCapacity(new Capacity($source->getBaseCapacity(), $source->getFullCapacity()));
        $clone->setHighlight($source->isHighlight());
        $clone->setGroup($source->getGroup());
        $clone->setSuperEvent($this->resolve($payload, 'superEvent', Event::class) ?? $source->getSuperEvent());
        $clone->setTargetGroup($this->resolve($payload, 'targetGroup', ParticipantGroup::class) ?? $source->getTargetGroup());
        $clone->setPublicOnWeb($source->isPublicOnWeb());
        $clone->setPublicInApp($source->isPublicInApp());

        // New time from request; fall back to the source slot's time (admin shifts later).
        $start = $this->dateTime($payload['startDateTime'] ?? null) ?? $source->getStartDateTime();
        $end = $this->dateTime($payload['endDateTime'] ?? null) ?? $source->getEndDateTime();
        if (null !== $start) {
            $clone->setStartDateTime($start);
        }
        if (null !== $end) {
            $clone->setEndDateTime($end);
        }

        $this->em->persist($clone);
        $this->em->flush();

        return $clone;
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
     * @template T of object
     * @param class-string<T> $expected
     * @return T|null
     */
    private function resolve(array $payload, string $key, string $expected): ?object
    {
        if (!array_key_exists($key, $payload) || null === $payload[$key]) {
            return null;
        }
        $value = $payload[$key];
        $iri = is_string($value) ? $value : (is_array($value) && isset($value['@id']) && is_string($value['@id']) ? $value['@id'] : null);
        if (null === $iri) {
            throw new BadRequestHttpException(sprintf('Pole "%s" musí být IRI odkaz.', $key));
        }
        try {
            $resolved = $this->iriConverter->getResourceFromIri($iri);
        } catch (\Throwable $e) {
            throw new BadRequestHttpException(sprintf('Neplatné IRI pro "%s": %s', $key, $iri), $e);
        }

        return $resolved instanceof $expected ? $resolved : null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    private function dateTime(mixed $value): ?DateTime
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }
        try {
            return new DateTime($value);
        } catch (\Throwable $e) {
            throw new BadRequestHttpException(sprintf('Neplatné datum: %s', $value), $e);
        }
    }
}
