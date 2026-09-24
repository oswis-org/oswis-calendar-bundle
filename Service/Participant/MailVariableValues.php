<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCoreBundle\Mail\Editor\MailEditorConfig;
use Twig\Environment;
use Twig\TemplateWrapper;

/**
 * Hodnoty údajů příjemce pro dialog „Vložit údaj příjemce" (dávka 2c, bod 4).
 *
 * PROČ: v dialogu byl vidět jen název údaje. Autor nevěděl, co u příjemce doopravdy vyjde, a že
 * u části výběru vyjde prázdno nebo 0 („zbývá zaplatit 0 Kč") zjistil až kontrolou po napsání.
 * Tady se ukáže hodnota u příjemce z náhledu a u kolika vybraných je údaj prázdný nebo 0.
 *
 * Počítá se nad TÝMŽ kontextem jako náhled a odeslání ({@see ParticipantMailContextFactory::createNeutral()}).
 * Údaje, které prázdné být smějí (`mayBeEmpty`, např. koncovka „-a" u mužů), se nepočítají.
 */
final readonly class MailVariableValues
{
    /**
     * Časový rozpočet pro počty prázdných hodnot. Měřeno 24. 9. 2026 na klonu: 309 příjemců = 4,7 s
     * (každý se dotahuje z databáze zvlášť, ~15 ms), podruhé 0,27 s. Dialog musí odpovědět hned —
     * proto náhodný vzorek, dokud stačí čas: projde-li se celý výběr, počet je přesný, jinak je to
     * odhad (`exact: false`) a dialog ho řekne slovy („u většiny"), ne číslem.
     */
    public const float BUDGET_SECONDS = 0.25;

    public function __construct(
        private Environment $twig,
        private MailEditorConfig $editorConfig,
        private ParticipantMailContextFactory $contextFactory,
    ) {
    }

    /**
     * @param list<Participant> $recipients výběr, nad kterým se počítá, kolik příjemců má údaj prázdný
     *
     * @return array{values: array<string, string>, empty: array<string, int>, checked: int, total: int, exact: bool}
     */
    public function compute(Participant $sample, array $recipients, float $budgetSeconds = self::BUDGET_SECONDS): array
    {
        $templates = [];
        $mayBeEmpty = [];
        foreach ($this->editorConfig->toArray()['variables'] as $variable) {
            $templates[$variable['expression']] = $this->twig->createTemplate('{{ '.$variable['expression'].' }}');
            $mayBeEmpty[$variable['expression']] = $variable['mayBeEmpty'];
        }

        $values = [];
        $context = $this->contextFactory->createNeutral($sample);
        foreach ($templates as $expression => $template) {
            $values[$expression] = self::render($template, $context) ?? '';
        }

        $empty = [];
        $checked = 0;
        $deadline = microtime(true) + $budgetSeconds;
        shuffle($recipients); // vzorek, ne prvních N — pořadí výběru bývá podle akce či data
        foreach ($recipients as $recipient) {
            if ($checked > 0 && microtime(true) > $deadline) {
                break;
            }
            ++$checked;
            $context = $this->contextFactory->createNeutral($recipient);
            foreach ($templates as $expression => $template) {
                if ($mayBeEmpty[$expression]) {
                    continue;
                }
                $value = self::render($template, $context);
                if (null === $value || '' === $value || '0' === $value) {
                    $empty[$expression] = ($empty[$expression] ?? 0) + 1;
                }
            }
        }

        return ['values' => $values, 'empty' => $empty, 'checked' => $checked, 'total' => count($recipients), 'exact' => $checked === count($recipients)];
    }

    /**
     * Hodnota jako text, jak ji uvidí příjemce (bez HTML, bez nadbytečných mezer), nebo null při chybě.
     *
     * @param array<string, mixed> $context
     */
    private static function render(TemplateWrapper $template, array $context): ?string
    {
        try {
            $text = html_entity_decode(strip_tags($template->render($context)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } catch (\Throwable) {
            return null;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
