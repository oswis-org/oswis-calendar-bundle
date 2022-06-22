<?php

namespace OswisOrg\OswisCalendarBundle\Service\Registration;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use Psr\Log\LoggerInterface;

class RegistrationFlagCategoryService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
    ) {
    }

    final public function create(RegistrationFlagCategory $flagType): ?RegistrationFlagCategory
    {
        try {
            $this->em->persist($flagType);
            $this->em->flush();
            $this->logger->info('CREATE: Created flag category (by service): '
                                .$flagType->getId()
                                .' '
                                .$flagType->getShortName()
                                .'.');

            return $flagType;
        } catch (Exception $e) {
            $this->logger->info('ERROR: RegistrationFlag category type not created (by service): '.$e->getMessage());

            return null;
        }
    }
}
