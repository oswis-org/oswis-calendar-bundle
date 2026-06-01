<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Evaluates a sandboxed boolean filter expression against a single {@see Participant}.
 *
 * The unified participant list lets admins combine arbitrary AND/OR/NOT predicates
 * (e.g. `hasFlag('panske-m') or hasFlag('damske-m')`,
 * `hasFlag('hotel-a') and not isPaid()`). Symfony ExpressionLanguage gives us a safe
 * sandbox: only the whitelisted functions registered here plus the supplied variables
 * are reachable — no arbitrary PHP. The whole feature is ROLE_ADMIN-only on top of that.
 *
 * Faceted flag checkboxes in the UI compile down to one of these expressions, so there
 * is a single evaluation path for both the simple and the advanced filter modes.
 *
 * Evaluation is per-participant in PHP over an already-loaded (scoped + primed)
 * collection — flag predicates cannot reasonably be pushed to SQL.
 */
final class ParticipantFilterEvaluator
{
    /**
     * Hard cap on expression length. The page is admin-only so abuse risk is low,
     * but an unbounded expression is a needless DoS surface.
     */
    public const int MAX_EXPRESSION_LENGTH = 2000;

    private ExpressionLanguage $expressionLanguage;

    public function __construct()
    {
        $this->expressionLanguage = new ExpressionLanguage();
        foreach ($this->buildFunctions() as $function) {
            $this->expressionLanguage->addFunction($function);
        }
    }

    /**
     * Whether the participant matches the given filter expression.
     *
     * An empty/null expression matches everything. A syntactically invalid expression
     * raises {@see BadRequestHttpException} (HTTP 400) rather than bubbling up as a 500.
     *
     * @throws BadRequestHttpException on syntax errors or over-long expressions
     */
    public function matches(Participant $participant, ?string $expression): bool
    {
        $expression = null === $expression ? '' : trim($expression);
        if ('' === $expression) {
            return true;
        }
        if (mb_strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            throw new BadRequestHttpException(
                sprintf('Filtrační výraz je příliš dlouhý (max %d znaků).', self::MAX_EXPRESSION_LENGTH),
            );
        }
        try {
            return (bool) $this->expressionLanguage->evaluate($expression, ['p' => $participant]);
        } catch (SyntaxError $syntaxError) {
            throw new BadRequestHttpException(
                sprintf('Neplatný filtrační výraz: %s', $syntaxError->getMessage()),
                $syntaxError,
            );
        }
    }

    /**
     * Validate an expression without evaluating it (used by the UI before applying).
     *
     * @return string|null null when valid, otherwise a human-readable error message
     */
    public function validate(?string $expression): ?string
    {
        $expression = null === $expression ? '' : trim($expression);
        if ('' === $expression) {
            return null;
        }
        if (mb_strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            return sprintf('Filtrační výraz je příliš dlouhý (max %d znaků).', self::MAX_EXPRESSION_LENGTH);
        }
        try {
            $this->expressionLanguage->parse($expression, ['p']);

            return null;
        } catch (SyntaxError $syntaxError) {
            return $syntaxError->getMessage();
        }
    }

    /**
     * Names of the whitelisted functions, for the advanced-filter autocomplete in the UI.
     *
     * @return list<string>
     */
    public function getFunctionNames(): array
    {
        return [
            'hasFlag', 'hasFlagInCategory', 'flagCount',
            'isPaid', 'isOverpaid', 'remainingPrice', 'remainingDeposit',
            'isDeleted', 'isActivated', 'hasNote', 'hasRegistration',
            'gender', 'eventSlug',
        ];
    }

    /**
     * @return list<ExpressionFunction>
     */
    private function buildFunctions(): array
    {
        return [
            $this->fn('hasFlag', static fn (Participant $p, string $slug): bool => self::activeFlagSlugs($p)[$slug] ?? false),
            $this->fn('hasFlagInCategory', static fn (Participant $p, string $catSlug): bool => self::flagCountInCategory($p, $catSlug) > 0),
            $this->fn('flagCount', static fn (Participant $p, string $catSlug): int => self::flagCountInCategory($p, $catSlug)),
            $this->fn('isPaid', static fn (Participant $p): bool => $p->getRemainingPrice() <= 0),
            $this->fn('isOverpaid', static fn (Participant $p): bool => $p->getRemainingPrice() < 0),
            $this->fn('remainingPrice', static fn (Participant $p): int => $p->getRemainingPrice()),
            $this->fn('remainingDeposit', static fn (Participant $p): int => $p->getRemainingDeposit()),
            $this->fn('isDeleted', static fn (Participant $p): bool => $p->isDeleted()),
            $this->fn('isActivated', static fn (Participant $p): bool => $p->hasActivatedContactUser()),
            $this->fn('hasNote', static fn (Participant $p): bool => self::hasNote($p)),
            $this->fn('hasRegistration', static fn (Participant $p): bool => null !== $p->getParticipantRegistration()),
            $this->fn('gender', static fn (Participant $p): string => $p->getContact()?->getGender() ?? ''),
            $this->fn('eventSlug', static fn (Participant $p): string => $p->getEvent()?->getSlug() ?? ''),
        ];
    }

    /**
     * Register a function whose evaluator receives the bound Participant ($values['p'])
     * as its first argument followed by the expression-supplied arguments.
     *
     * @param callable $callable receives (Participant, ...$args) and returns bool|int|string
     */
    private function fn(string $name, callable $callable): ExpressionFunction
    {
        return new ExpressionFunction(
            $name,
            // Compiler: never used (evaluate-only) but ExpressionFunction requires it.
            static fn (string ...$args): string => sprintf('%s(%s)', $name, implode(', ', $args)),
            /**
             * @param array{p: Participant} $values
             */
            static fn (array $values, mixed ...$args): mixed => $callable($values['p'], ...$args),
        );
    }

    /**
     * Map of active flag slug => true for fast `hasFlag()` lookups.
     *
     * @return array<string, true>
     */
    private static function activeFlagSlugs(Participant $participant): array
    {
        $slugs = [];
        /** @var ParticipantFlag $participantFlag */
        foreach ($participant->getParticipantFlags(null, null, true) as $participantFlag) {
            $slug = $participantFlag->getFlag()?->getSlug();
            if (null !== $slug && '' !== $slug) {
                $slugs[$slug] = true;
            }
        }

        return $slugs;
    }

    private static function flagCountInCategory(Participant $participant, string $categorySlug): int
    {
        $count = 0;
        /** @var ParticipantFlag $participantFlag */
        foreach ($participant->getParticipantFlags(null, null, true) as $participantFlag) {
            if ($participantFlag->getFlag()?->getCategory()?->getSlug() === $categorySlug) {
                ++$count;
            }
        }

        return $count;
    }

    private static function hasNote(Participant $participant): bool
    {
        /** @var ParticipantNote $note */
        foreach ($participant->getNotes() as $note) {
            if (!$note->isDeleted() && null !== ($text = $note->getTextValue()) && '' !== trim($text)) {
                return true;
            }
        }

        return false;
    }
}
