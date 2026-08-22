<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Price;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\RegistrationFlagOfferEditType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Web-admin edit for a RegistrationFlagOffer (příznak v rozsahu).
 *
 * Each year-clone produces N flag offers per turnus (one per ubytování
 * type, tričko velikost, fakulta, …). After clone the operator needs to
 * tweak prices, capacities, or descriptions — this is the screen for
 * that. Year-clone wizard's Step 2 covers initial bulk-edit, but ad-hoc
 * follow-up edits land here.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminRegistrationFlagOfferController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function edit(Request $request, int $id): Response
    {
        $offer = $this->em->find(RegistrationFlagOffer::class, $id);
        if (!$offer instanceof RegistrationFlagOffer) {
            throw $this->createNotFoundException('Příznak #'.$id.' nenalezen.');
        }

        // Původní kapacita PŘED zpracováním formuláře — po `handleRequest()` už je v entitě
        // nová hodnota a stará je nenávratně pryč.
        $puvodniZaklad = $offer->getBaseCapacity();
        $puvodniPlna = $offer->getFullCapacity();

        $form = $this->createForm(RegistrationFlagOfferEditType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Re-wrap price/deposit/capacity into the Price + Capacity DTOs
            // so the underlying traits stay consistent with the year-clone
            // and API Platform write paths.
            $offer->setEventPrice(new Price(
                $offer->getPrice() ?: null,
                $offer->getDepositValue() ?: null,
            ));
            $offer->setCapacity(new Capacity(
                $offer->getBaseCapacity(),
                $offer->getFullCapacity(),
            ));
            $this->em->persist($offer);
            $this->em->flush();

            /*
             * Změna kapacity se ZAZNAMENÁVÁ. Historie se nikde neukládá, takže když se později
             * ptáme „jak mohla vzniknout přeplněná kapacita", nedá se zjistit, jaký strop platil
             * v době registrace — a bez toho je každé vysvětlení jen dohad (naráženo 22. 8. 2026
             * u kempu 186/185). Obsazenost se loguje s nimi, ať je vidět, jestli nová hodnota
             * sedí se skutečností už v okamžiku uložení.
             */
            if ($puvodniZaklad !== $offer->getBaseCapacity() || $puvodniPlna !== $offer->getFullCapacity()) {
                $this->logger->info(sprintf(
                    'KAPACITA PŘÍZNAKU: #%d „%s" změněna ze základní %s / plné %s na základní %s / plnou %s '
                    .'(obsazeno %d; upravil %s).',
                    $id,
                    $offer->getName() ?? '',
                    $puvodniZaklad ?? 'neomezeno',
                    $puvodniPlna ?? 'neomezeno',
                    $offer->getBaseCapacity() ?? 'neomezeno',
                    $offer->getFullCapacity() ?? 'neomezeno',
                    $offer->getUsageInt(),
                    $this->getUser()?->getUserIdentifier() ?? 'neznámý',
                ));
            }

            $this->addFlash('success', sprintf('Příznak „%s" uložen.', $offer->getName() ?? '#'.$id));

            // Redirect to a parent — flag offers don't expose their owning
            // event directly, so we fall back to the catalog (operator
            // sees the just-edited row in context there).
            return new RedirectResponse($this->generateUrl(
                'oswis_org_oswis_calendar_web_admin_catalog',
            ));
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/flag_offer_edit.html.twig', [
            'offer'      => $offer,
            'form'       => $form,
            'pageTitle'  => sprintf('Upravit příznak: %s', $offer->getName() ?? ''),
            'page_title' => sprintf('Upravit příznak: %s :: ADMIN', $offer->getName() ?? ''),
        ]);
    }
}
