<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Price;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\RegistrationOfferEditType;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationOfferRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Web-admin edit for a RegistrationOffer (registrační rozsah / přihláška na akci).
 *
 * Operator fixes per-range price / deposit / capacity / dates / visibility
 * without going through API Platform's raw JSON CRUD. The wizard creates
 * the rows, the year-clone fixes them in bulk, but follow-up tweaks (e.g.
 * the launch-day "this turnus has 50 more beds than I thought") live here.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminRegistrationOfferController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function edit(Request $request, string $slug): Response
    {
        $repo = $this->em->getRepository(RegistrationOffer::class);
        assert($repo instanceof RegistrationOfferRepository);
        $offer = $repo->findOneBy(['slug' => $slug]);
        if (!$offer instanceof RegistrationOffer) {
            throw $this->createNotFoundException('Rozsah '.$slug.' nenalezen.');
        }

        $form = $this->createForm(RegistrationOfferEditType::class, $offer);
        // endDateTime is not auto-mapped (the trait uses `setEndDateTime`
        // with a second arg). Pre-fill it manually so the user sees the
        // current value.
        $form->get('endDateTime')->setData($offer->getEndDate());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $end = $form->get('endDateTime')->getData();
            if ($end instanceof \DateTimeInterface) {
                $offer->setEndDateTime(\DateTime::createFromInterface($end), true);
            }
            // Price + deposit on the trait are stored as a Price value
            // object; the form binds them onto the entity's plain price /
            // depositValue fields. Re-wrap into Price so the trait stays
            // consistent with how the wizard / API Platform write it.
            $offer->setEventPrice(new Price(
                $offer->getPrice(null, false) ?: null,
                $offer->getDepositValue(null, false) ?: null,
            ));
            $offer->setCapacity(new Capacity(
                $offer->getBaseCapacity(),
                $offer->getFullCapacity(),
            ));
            $this->em->persist($offer);
            $this->em->flush();
            $this->addFlash('success', sprintf('Rozsah „%s" uložen.', $offer->getName() ?? $offer->getSlug()));

            $event = $offer->getEvent();

            return new RedirectResponse($this->generateUrl(
                'oswis_org_oswis_calendar_web_admin_event',
                ['eventSlug' => $event?->getSlug() ?? $offer->getSlug()],
            ));
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/reg_offer_edit.html.twig', [
            'offer'      => $offer,
            'form'       => $form,
            'pageTitle'  => sprintf('Upravit rozsah: %s', $offer->getName() ?? ''),
            'page_title' => sprintf('Upravit rozsah: %s :: ADMIN', $offer->getName() ?? ''),
        ]);
    }
}
