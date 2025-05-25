<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Traits\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Metadata\ApiFilter;
use Doctrine\ORM\Mapping\Column;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Price;
use OswisOrg\OswisCoreBundle\Filter\SearchFilter;
use OswisOrg\OswisCoreBundle\Traits\Payment\DepositValueTrait;

trait PriceTrait
{
    use DepositValueTrait;

    #[Column(type: 'integer', nullable: true)]
    #[ApiFilter(SearchFilter::class, strategy: 'exact')]
    #[ApiFilter(RangeFilter::class)]
    #[ApiFilter(OrderFilter::class)]
    protected ?int $price = null;

    public function setEventPrice(?Price $eventPrice): void
    {
        $this->setPrice($eventPrice?->price);
        $this->setDepositValue($eventPrice?->deposit);
    }

    public function getEventPrice(): Price
    {
        return new Price($this->getPrice(), $this->getDepositValue());
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
