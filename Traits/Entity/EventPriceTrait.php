<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Traits\Entity;

use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\EventPrice;
use OswisOrg\OswisCoreBundle\Traits\Payment\DepositValueTrait;

trait EventPriceTrait
{
    use DepositValueTrait;

    /**
     * Price.
     * @Doctrine\ORM\Mapping\Column(type="integer", nullable=true)
     * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter::class, strategy="exact")
     * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\RangeFilter::class)
     * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter::class)
     */
    protected ?int $price = null;

    public function setEventPrice(?EventPrice $eventPrice): void
    {
        $this->setPrice($eventPrice ? $eventPrice->price : null);
        $this->setDepositValue($eventPrice ? $eventPrice->deposit : null);
    }

    public function getEventPrice(): EventPrice
    {
        return new EventPrice($this->getPrice(), $this->getDepositValue());
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): void
    {
        $this->price = $price;
    }
}