<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Twig\Extension;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Nabídka ročníku a jeho turnusů pro přepínač v podmenu administrace.
 *
 * PROČ: podmenu turnusu (Přehled, Účastníci, Pásky, Check-in, …) dosud jen napsalo, u které akce
 * stojíš, ale přepnout se jinam odtud nešlo — bylo nutné se vrátit do Akcí a projít výběrem znovu.
 * A protože podmenu visí i na ročníku, který sám žádné přihlášky nemá, končily tam stránky
 * s nulou bez vysvětlení (výtka uživatele 17. 9. 2026 u stránky Pásky).
 */
final class EventSwitcherExtension extends AbstractExtension
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('oswis_prepinac_akci', $this->choices(...))];
    }

    /**
     * Ročník a jeho turnusy; `current` označuje tu právě otevřenou.
     *
     * @return list<array{slug: string, label: string, current: bool, year: bool}>
     */
    public function choices(?string $eventSlug): array
    {
        if (null === $eventSlug || '' === $eventSlug) {
            return [];
        }
        $event = $this->em->getRepository(Event::class)->findOneBy(['slug' => $eventSlug]);
        if (!$event instanceof Event) {
            return [];
        }
        $year = $event->isYear() ? $event : $event->getSuperEvent();
        if (!$year instanceof Event) {
            return [];
        }
        $choices = [$this->choice($year, $eventSlug, true)];
        foreach ($this->batches($year) as $batch) {
            $choices[] = $this->choice($batch, $eventSlug, false);
        }

        return array_values(array_filter($choices, static fn (?array $row): bool => null !== $row));
    }

    /** @return list<Event> turnusy ročníku (bez smazaných), od nejstaršího */
    private function batches(Event $year): array
    {
        $result = $this->em->createQuery(
            'SELECT e FROM '.Event::class.' e JOIN e.category c
             WHERE e.superEvent = :rocnik AND e.deletedAt IS NULL AND c.type = :turnus
             ORDER BY e.startDateTime ASC, e.id ASC'
        )->setParameter('rocnik', $year)
            ->setParameter('turnus', EventCategory::BATCH_OF_EVENT)
            ->getResult();

        return is_array($result) ? array_values(array_filter($result, static fn (mixed $row): bool => $row instanceof Event)) : [];
    }

    /** @return array{slug: string, label: string, current: bool, year: bool}|null */
    private function choice(Event $event, string $currentSlug, bool $isYear): ?array
    {
        $slug = $event->getSlug();
        if ('' === $slug) {
            return null;
        }
        $label = $event->getShortName() ?? $event->getName() ?? $slug;

        return [
            'slug'    => $slug,
            'label'   => $isYear ? $label.' (celý ročník)' : $label,
            'current' => $slug === $currentSlug,
            'year'    => $isYear,
        ];
    }
}
