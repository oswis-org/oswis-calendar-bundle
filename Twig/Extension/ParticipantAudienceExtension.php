<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Twig\Extension;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantFilterEvaluator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `pravidla("isPaid() and eventSlug() == 'x'")` — podmínka ze stavebnice v editoru mailu (dávka 2c, bod 7).
 *
 * Vyhodnotí výraz filtru přihlášek ({@see ParticipantFilterEvaluator}, bezpečný slovník) nad přihláškou
 * z kontextu mailu. Stavebnice ukládá obyčejnou podmínku Twigu `{% if pravidla("…") %}` — žádný druhý
 * formát podmínek vedle `{% if %}`. Mail bez přihlášky (účtové maily) pravidla nemá čím vyhodnotit:
 * blok se ZOBRAZÍ, nikdy tiše nezmizí. Chybný výraz je chyba šablony (kontrola ho zachytí při uložení).
 */
final class ParticipantAudienceExtension extends AbstractExtension
{
    public function __construct(private readonly ParticipantFilterEvaluator $evaluator)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('pravidla', $this->pravidla(...), ['needs_context' => true])];
    }

    /** @param array<string, mixed> $context */
    public function pravidla(array $context, string $vyraz): bool
    {
        $participant = $context['participant'] ?? null;
        if (!$participant instanceof Participant) {
            return true;
        }
        try {
            return $this->evaluator->matches($participant, $vyraz);
        } catch (BadRequestHttpException $chyba) {
            throw new RuntimeError($chyba->getMessage(), -1, null, $chyba);
        }
    }
}
