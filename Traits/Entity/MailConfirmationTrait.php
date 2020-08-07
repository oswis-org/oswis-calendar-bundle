<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Traits\Entity;

use DateTime;

trait MailConfirmationTrait
{
    /**
     * @Doctrine\ORM\Mapping\Column(type="datetime", nullable=true, options={"default" : null})
     * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter::class, strategy="ipartial")
     * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter::class)
     * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\ExistsFilter::class)
     * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter::class)
     */
    protected ?DateTime $confirmedByMailAt = null;

    public function isConfirmedByMail(): bool
    {
        return (bool)$this->getConfirmedByMailAt();
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