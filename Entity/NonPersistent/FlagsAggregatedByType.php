<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\AbstractClass\AbstractEventFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;

class FlagsAggregatedByType
{
    public array $flagTypes = [];

    public function __construct(?Collection $flags = null)
    {
        $this->addFlags($flags);
    }

    public function addFlags(?Collection $flags): void
    {
        foreach ($flags as $flag) {
            if ($flag instanceof AbstractEventFlag) {
                $this->addFlag($flag);
            }
        }
    }

    public function addFlag(?AbstractEventFlag $flag): void
    {

    }

    /**
     * Gets array of flags aggregated by their types.
     *
     * @param Collection $flags
     *
     * @return array
     */
    public static function getFlagsAggregatedByType(Collection $flags): array
    {
        $out = [];
        foreach ($flags as $flag) {
            if ($flag instanceof ParticipantFlag) {
                $flagTypeSlug = $flag->getFlagType() ? $flag->getFlagType()->getSlug() : '0';
                $flagSlug = $flag->getSlug();
                $out[$flagTypeSlug] ??= [];
                $out[$flagTypeSlug][$flagSlug]['flag'] = $flag;
                $count = $out[$flagTypeSlug][$flagSlug]['count'] ? $out[$flagTypeSlug][$flagSlug]['count'] + 1 : 1;
                $out[$flagTypeSlug][$flagSlug]['count'] = $count;
            }
        }

        return $out;
    }

    public function removeFlags(?Collection $flags): void
    {
        foreach ($flags as $flag) {
            if ($flag instanceof AbstractEventFlag) {
                $this->removeFlag($flag);
            }
        }
    }

    public function removeFlag(?AbstractEventFlag $flag): void
    {

    }


}
