<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Pro kolik příjemců platí podmínka ze stavebnice („Platí pro 128 z 534") — dávka 2c, bod 7.
 *
 * Mezní stavy se musí říct nahlas (nikdo / všichni) — bez toho je nejčastější chyba tichá. Časový
 * rozpočet a náhodný vzorek jako u hodnot údajů ({@see MailVariableValues}): projde-li se výběr celý,
 * počet je přesný, jinak odhad (`exact: false`).
 */
final readonly class MailAudienceCounter
{
    public function __construct(private ParticipantFilterEvaluator $evaluator)
    {
    }

    /**
     * @param list<Participant> $recipients
     *
     * @return array{matches: int, checked: int, total: int, exact: bool, sample: ?bool, error: ?string}
     */
    public function count(string $expression, Participant $sample, array $recipients, float $budgetSeconds = MailVariableValues::BUDGET_SECONDS): array
    {
        if (null !== ($chyba = $this->evaluator->validate($expression))) {
            return ['matches' => 0, 'checked' => 0, 'total' => count($recipients), 'exact' => false, 'sample' => null, 'error' => $chyba];
        }
        try {
            $sampleMatches = $this->evaluator->matches($sample, $expression);
            $matches = 0;
            $checked = 0;
            $deadline = microtime(true) + $budgetSeconds;
            shuffle($recipients);
            foreach ($recipients as $recipient) {
                if ($checked > 0 && microtime(true) > $deadline) {
                    break;
                }
                ++$checked;
                if ($this->evaluator->matches($recipient, $expression)) {
                    ++$matches;
                }
            }
        } catch (BadRequestHttpException $e) {
            return ['matches' => 0, 'checked' => 0, 'total' => count($recipients), 'exact' => false, 'sample' => null, 'error' => $e->getMessage()];
        }

        return ['matches' => $matches, 'checked' => $checked, 'total' => count($recipients), 'exact' => $checked === count($recipients), 'sample' => $sampleMatches, 'error' => null];
    }
}
