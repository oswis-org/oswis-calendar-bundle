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
    /** @var array<array{pattern: string, value: string}> $patterns */
    public array $patterns = [];

    /** @var string|null */
    private ?string $defaultEvent = null;

    /** @var string[] Fallback default events. */
    private array $defaultEventFallbacks = [];

    /**
     * @var array{participant_activated: string|null, participant_invalid_token: string|null, participant_activation_error: string|null, participant_verification_resent: string|null} External redirects.
     */
    private array $externalRedirects = [
        'participant_activated' => null,
        'participant_invalid_token' => null,
        'participant_activation_error' => null,
        'participant_verification_resent' => null,
    ];

    /**
     * OswisCalendarSettingsProvider constructor.
     *
     * @param string|null   $defaultEvent
     * @param string[]|null $defaultEventFallbacks
     * @param array{participant_activated: string|null, participant_invalid_token: string|null, participant_activation_error: string|null, participant_verification_resent: string|null}|null $externalRedirects
     */
    public function __construct(
        ?string $defaultEvent = null,
        ?array $defaultEventFallbacks = null,
        ?array $externalRedirects = null,
    ) {
        $this->patterns = [
            [
                'pattern' => "/(.*){\s*(year)\s*([\+\-]?)\s*(\d*)}(.*)/",
                // [1] prefix [2] 'year' [3] sign [4] number [5] suffix
                'value' => date('Y'),
            ],
        ];
        $this->setDefaultEvent($defaultEvent);
        $this->setDefaultEventFallbacks($defaultEventFallbacks ?? []);
        $this->setExternalRedirects($externalRedirects);
    }

    /**
     * @param string|null $slug
     *
     * @return string|null
     */
    public function processSpecialSlug(?string $slug): ?string
    {
        if (empty($slug)) {
            return null;
        }
        foreach ($this->patterns as $pattern) {
            /** @var string[] $parts */
            $parts = $this->regexMatch($slug, $pattern['pattern']);
            $math = (string)$this->processMath((int)$pattern['value'], (string)$parts[3], (int)$parts[4]);
            $slug = empty($parts) ? $slug : ($parts[1].$math.$parts[5]);
        }

        return $slug;
    }

    /**
     * @param string $slug
     * @param string $pattern
     *
     * @return array
     */
    private function regexMatch(string $slug, string $pattern = '//'): array
    {
        /** @var string[] $parts */
        $parts = [];
        preg_match($pattern, $slug, $parts);
        if (empty($parts)) {
            return [];
        }

        return [
            $parts[0],    // Whole string.
            $parts[1],    // Prefix.
            $parts[2],    // Keyword ("year").
            $parts[3],    // Sign.
            $parts[4],    // Number.
            $parts[5],    // Suffix.
        ];
    }

    /**
     * @param int $a
     * @param string $sign
     * @param int $b
     *
     * @return int
     */
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

    /**
     * @return array
     */
    public function getArray(): array
    {
        return [
            'default_event' => $this->getDefaultEvent(),
            'default_event_fallback' => $this->getDefaultEventFallbacks(),
        ];
    }

    /**
     * @return string|null
     */
    public function getDefaultEvent(): ?string
    {
        return $this->defaultEvent;
    }

    /**
     * @param string|null $slug
     */
    public function setDefaultEvent(?string $slug): void
    {
        $this->defaultEvent = $this->processSpecialSlug($slug);
    }

    /**
     * @return string[]
     */
    public function getDefaultEventFallbacks(): array
    {
        return $this->defaultEventFallbacks;
    }

    /**
     * @param string[] $fallbacks
     */
    public function setDefaultEventFallbacks(array $fallbacks): void
    {
        $this->defaultEventFallbacks = [];
        foreach ($fallbacks as $fallback) {
            $fallbackString = $this->processSpecialSlug($fallback);
            if ($fallbackString) {
                $this->defaultEventFallbacks[] = $fallbackString;
            }
        }
    }

    /**
     * @return array{participant_activated: string|null, participant_invalid_token: string|null, participant_activation_error: string|null, participant_verification_resent: string|null}
     */
    public function getExternalRedirects(): array
    {
        return $this->externalRedirects;
    }

    /**
     * @param array{participant_activated: string|null, participant_invalid_token: string|null, participant_activation_error: string|null, participant_verification_resent: string|null}|null $externalRedirects
     * @return void
     */
    public function setExternalRedirects(?array $externalRedirects): void
    {
        if ($externalRedirects) {
            $this->externalRedirects = $externalRedirects;
        }
    }
}
