<?php

namespace OswisOrg\OswisCalendarBundle\Provider;

/**
 * Provider of settings for core module of OSWIS.
 */
class OswisCalendarSettingsProvider
{
    protected ?string $defaultEvent = null;

    public function __construct(?string $defaultEvent)
    {
        $this->defaultEvent = $defaultEvent;
    }

    final public function getArray(): array
    {
        return [
            'default_event' => $this->getDefaultEvent(),
        ];
    }

    final public function getDefaultEvent(): ?string
    {
        return $this->defaultEvent;
    }
}
