<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffAssignment;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Write processor pro {@see StaffAssignment} (POST/PUT).
 *
 * Skaláry (externalName, časy, lead/during/trail, excluded) denormalizer nastaví sám; RELACE
 * (turnus/activity/role/participant/team) ale default denormalizer neresolvuje do managed entit —
 * postaví prázdné embedded → Doctrine „new entity found through relationship" na flush. Proto je
 * čteme z IRI v payloadu, resolvujeme přes IriConverter a setnem. Mirror {@see ProgramApiProcessor}.
 *
 * @implements ProcessorInterface<object, mixed>
 */
final class StaffAssignmentProcessor implements ProcessorInterface
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
        if ($data instanceof StaffAssignment) {
            $payload = $this->payload();
            if ([] !== $payload) {
                $this->resolveSingle($data, $payload, 'turnus', 'setTurnus');
                $this->resolveSingle($data, $payload, 'activity', 'setActivity');
                $this->resolveSingle($data, $payload, 'role', 'setRole');
                $this->resolveSingle($data, $payload, 'participant', 'setParticipant');
                $this->resolveSingle($data, $payload, 'team', 'setTeam');
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
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
    private function resolveSingle(StaffAssignment $data, array $payload, string $key, string $setter): void
    {
        if (!array_key_exists($key, $payload)) {
            return;
        }
        $value = $payload[$key];
        if (null === $value) {
            $data->$setter(null);

            return;
        }
        $data->$setter($this->resolve($key, $value));
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
            throw new BadRequestHttpException(sprintf('Neplatné IRI pro "%s": %s', $key, $iri), $e);
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
