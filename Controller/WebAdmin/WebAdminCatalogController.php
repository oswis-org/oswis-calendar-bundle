<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only catalog of building blocks the admin sets up before opening
 * registrations: flags ("KEMP", "Dámské S"), flag categories ("Typ ubytování",
 * "Velikost trička"), registration offers (per-category tickets) and per-offer
 * flag pricing. Editing happens through the API Platform endpoints these
 * entities expose — the catalog page wires admins to the right entity quickly.
 */
#[IsGranted('ROLE_MANAGER')]
final class WebAdminCatalogController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function index(string $tab = 'flags'): Response
    {
        $tab = in_array($tab, ['flags', 'categories', 'offers', 'flag-offers'], true) ? $tab : 'flags';

        $data = [
            'pageTitle'  => 'Katalog příznaků a nabídek',
            'page_title' => 'Katalog :: ADMIN',
            'activeTab'  => $tab,
        ];

        switch ($tab) {
            case 'flags':
                $data['flags'] = $this->em
                    ->getRepository(RegistrationFlag::class)
                    ->findBy([], ['id' => 'ASC']);
                break;
            case 'categories':
                $data['categories'] = $this->em
                    ->getRepository(RegistrationFlagCategory::class)
                    ->findBy([], ['id' => 'ASC']);
                break;
            case 'offers':
                $data['offers'] = $this->em
                    ->getRepository(RegistrationOffer::class)
                    ->findBy([], ['id' => 'DESC'], 200);
                break;
            case 'flag-offers':
                $data['flagOffers'] = $this->em
                    ->getRepository(RegistrationFlagOffer::class)
                    ->findBy([], ['id' => 'DESC'], 200);
                break;
        }

        // Counts go to the tab badges regardless of which tab is active.
        $data['counts'] = [
            'flags'       => $this->em->getRepository(RegistrationFlag::class)->count([]),
            'categories'  => $this->em->getRepository(RegistrationFlagCategory::class)->count([]),
            'offers'      => $this->em->getRepository(RegistrationOffer::class)->count([]),
            'flag-offers' => $this->em->getRepository(RegistrationFlagOffer::class)->count([]),
        ];

        return $this->render('@OswisOrgOswisCalendar/web_admin/catalog/index.html.twig', $data);
    }
}
