<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Provider;

/**
 * Provider of settings for core module of OSWIS.
 */
class OswisCalendarSettingsProvider
{
    public array $patterns = [];

    protected ?string $defaultEvent = null;

    protected ?string $defaultEventFallback = null;

    public function __construct(?string $defaultEvent = null, ?string $defaultEventFallback = null)
    {
        $this->patterns = [
            [
                'pattern' => "/(.*){\s*(year)\s*([\+\-]?)\s*(\d*)}(.*)/", // [1] prefix [2] 'year' [3] sign [4] number [5] suffix
                'value'   => date('Y'),
            ],
        ];
        $this->setDefaultEvent($defaultEvent);
        $this->setDefaultEventFallback($defaultEventFallback);
    }

    public function processSpecialSlug(?string $slug): ?string
    {
        if (empty($slug)) {
            return null;
        }
        foreach ($this->patterns as $pattern) {
            $parts = $this->regexMatch($slug, $pattern['pattern']);
            $slug = empty($parts) ? $slug : $parts[1].$this->processMath((int)$pattern['value'], (string)$parts[3], (int)$parts[4]).$parts[5];
        }

        return $slug;
    }

    private function regexMatch(string $slug, string $pattern = '//'): array
    {
        $parts = null;
        preg_match($pattern, $slug, $parts);
        if (empty($parts)) {
            return [];
        }

        return [
            $parts[0] ?? '',    // Whole string.
            $parts[1] ?? '',    // Prefix.
            $parts[2] ?? '',    // Keyword ("year").
            $parts[3] ?? null,  // Sign.
            $parts[4] ?? 0,     // Number.
            $parts[5] ?? '',    // Suffix.
        ];
    }

    private function processMath(int $a, string $sign, int $b): int
    {
        if ($sign === '+') {
            $a += $b;
        }
        if ($sign === '-') {
            $a -= $b;
        }

        return $a;
    }

    public function getArray(): array
    {
        return [
            'default_event'          => $this->getDefaultEvent(),
            'default_event_fallback' => $this->getDefaultEventFallback(),
        ];
    }

    public function getDefaultEvent(): ?string
    {
        return $this->defaultEvent;
    }

    public function setDefaultEvent(?string $slug): void
    {
        $this->defaultEvent = $this->processSpecialSlug($slug);
    }

    public function getDefaultEventFallback(): ?string
    {
        return $this->defaultEventFallback;
    }

    public function setDefaultEventFallback(?string $slug): void
    {
        $this->defaultEventFallback = $this->processSpecialSlug($slug);
    }
}
