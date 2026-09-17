<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Mail;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCoreBundle\Mail\Link\MailLinkTarget;
use OswisOrg\OswisCoreBundle\Mail\Link\MailLinkTargetProviderInterface;

/**
 * Cíle odkazů z kalendáře: stránka akce (turnusu) a formulář přihlášky.
 *
 * PROČ nabídka z databáze a ne ruční psaní adresy: adresa se skládá až při odeslání, takže odkaz
 * nezastará — a hlavně se nedá napsat překlep, který by lidi poslal na 404.
 */
final readonly class ParticipantLinkTargetProvider implements MailLinkTargetProviderInterface
{
    /** Kolik akcí a nabídek nabídnout (od nejnovějších) — nabídka v dialogu, ne archiv. */
    private const int LIMIT = 30;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function getLinkTargets(): iterable
    {
        yield new MailLinkTarget(
            key: 'akce',
            label: 'Stránka akce (turnusu)',
            group: 'Seznamovák',
            route: 'oswis_org_oswis_calendar_web_event',
            parameter: 'eventSlug',
            options: $this->eventOptions(),
            hint: 'Veřejná stránka s termínem, místem a informacemi.',
        );

        yield new MailLinkTarget(
            key: 'prihlaska',
            label: 'Formulář přihlášky',
            group: 'Seznamovák',
            route: 'oswis_org_oswis_calendar_web_registration',
            parameter: 'rangeSlug',
            options: $this->offerOptions(),
            hint: 'Otevře se jen tehdy, když přihlášky do turnusu běží.',
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function eventOptions(): array
    {
        // Dvě podmínky, každá z jiného důvodu:
        // 1. DRUH akce — ročník nebo turnus (`Event::isYearOrBatch()`, stejné rozlišení jako v API
        //    `EventVisibleToUserExtension`). Bez toho by se do nabídky dostaly podakce programu
        //    („Společná fotka", „Informační schůze instruktorů"), které jsou v systému taky akce,
        //    ale vlastní stránku nemají.
        // 2. `publicOnWeb` — veřejná stránka akce bez něj vrací 404 (`EventController::showEvent`),
        //    takže odkaz na ni by byl rozbitý.
        $query = $this->em->createQuery(
            'SELECT e FROM '.Event::class.' e JOIN e.category c
             WHERE e.deletedAt IS NULL AND e.slug IS NOT NULL AND e.publicOnWeb = TRUE
               AND c.type IN (:druhy)
             ORDER BY e.startDateTime DESC'
        )->setParameter('druhy', [EventCategory::YEAR_OF_EVENT, EventCategory::BATCH_OF_EVENT])
            ->setMaxResults(self::LIMIT);
        $options = [];
        foreach ($query->toIterable() as $event) {
            if (!$event instanceof Event || '' === $event->getSlug()) {
                continue;
            }
            $start = $event->getStartDateTime();
            // Akce bez jména se pozná aspoň podle adresy — do nabídky patří, jen bez hezkého popisku.
            $name = $event->getName() ?? $event->getSlug();
            $options[] = [
                'value' => $event->getSlug(),
                'label' => null === $start ? $name : sprintf('%s (%s)', $name, $start->format('Y')),
            ];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    private function offerOptions(): array
    {
        $query = $this->em->createQuery(
            'SELECT o FROM '.RegistrationOffer::class.' o WHERE o.slug IS NOT NULL ORDER BY o.startDateTime DESC'
        )->setMaxResults(self::LIMIT);
        $options = [];
        foreach ($query->toIterable() as $offer) {
            if (!$offer instanceof RegistrationOffer || '' === $offer->getSlug()) {
                continue;
            }
            $event = $offer->getEvent();
            $name = $offer->getName() ?? $offer->getSlug();
            $options[] = [
                'value' => $offer->getSlug(),
                'label' => null === $event ? $name : sprintf('%s — %s', $event->getName() ?? '', $name),
            ];
        }

        return $options;
    }
}
