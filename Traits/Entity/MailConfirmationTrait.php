<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Traits\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use DateTime;
use Doctrine\ORM\Mapping\Column;
use OswisOrg\OswisCoreBundle\Filter\SearchFilter;

trait MailConfirmationTrait
{
    #[Column(type: 'datetime', nullable: true, options: ['default' => null])]
    #[ApiFilter(SearchFilter::class, strategy: 'ipartial')]
    #[ApiFilter(DateFilter::class)]
    #[ApiFilter(ExistsFilter::class)]
    #[ApiFilter(OrderFilter::class)]
    protected ?DateTime $confirmedByMailAt = null;

    public function isConfirmedByMail(): bool
    {
        return null !== $this->getConfirmedByMailAt();
    }

    public function getConfirmedByMailAt(): ?DateTime
    {
        return $this->confirmedByMailAt;
    }

    public function setConfirmedByMailAt(?DateTime $confirmedByMailAt): void
    {
        $this->confirmedByMailAt = $confirmedByMailAt;
    }
}