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

    public function __construct(?string $defaultEvent)
    {
        $this->patterns = [
            [
                'pattern' => "/(.*){\s*year\s*([\+\-])\s*(\d*)}(.*)/", // [0] prefix [1] 'year' [2] sign [3] number [4] suffix
                'value'   => date('Y'),
            ],
        ];
        $this->setDefaultEvent($defaultEvent);
    }

    public function setDefaultEvent(?string $slug): void
    {
        $this->defaultEvent = $this->processSpecialSlug($slug);
    }

    public function processSpecialSlug(?string $slug): ?string
    {
        if (empty($slug)) {
            return null;
        }
        foreach ($this->patterns as $pattern) {
            $parts = preg_split($pattern, $slug);
            if (empty($parts)) {
                continue;
            }
            $slug = $parts[0].$this->processMath(date('Y'), $parts[2], $parts[3]).$parts[4];
        }

        return $slug;
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
            'default_event' => $this->getDefaultEvent(),
        ];
    }

    public function getDefaultEvent(): ?string
    {
        return $this->processSpecialSlug($this->defaultEvent);
    }
}
