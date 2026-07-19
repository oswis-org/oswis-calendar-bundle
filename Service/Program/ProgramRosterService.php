<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Program;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventStaffAssignment;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\StaffTeam;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;

/**
 * Zápisová strana rozpisu služeb — SDÍLENÉ jádro pro web admin i API.
 *
 * Stejný vzor jako {@see \OswisOrg\OswisCalendarBundle\Service\CheckIn\CheckInService}, které sdílí
 * jedno zápisové jádro mezi web adminem a API procesorem check-inu. Tady totéž: {@see assignShift()}
 * volá jak web editor, tak {@see \OswisOrg\OswisCalendarBundle\State\RosterShiftAssignProcessor}
 * (POST /api/program_service_roster), takže se logika nepíše dvakrát.
 *
 * Blbuvzdornost: volající řeší jen KDO + JAKÁ služba + KDY. Nositelem času směny je service-Event
 * (jeho vlastní časový rozsah — rozhodnutí „časově, ne půldny", 2026-07-19); ten se ZA scénou najde
 * nebo založí, takže se nikdo nestará o „zakládání eventu". Jedna směna může mít víc lidí (dvojice
 * Gabča+Pája = dvě přiřazení na TÉŽE service-Eventu).
 */
final class ProgramRosterService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Přiřadí osobu / tým / externí jméno na službu: najde-nebo-založí service-Event pro
     * (turnus, typ služby, čas) a k němu přiřazení. IDEMPOTENTNÍ — stejný účastník/tým/externí
     * na téže směně se nepřidá dvakrát (jen se případně aktualizuje role). Vrací výsledné přiřazení.
     */
    public function assignShift(
        Event $turnus,
        string $serviceType,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?Participant $participant = null,
        ?StaffTeam $team = null,
        ?string $externalName = null,
        ?string $roleLabel = null,
    ): EventStaffAssignment {
        if (!EventCategory::isServiceType($serviceType)) {
            throw new InvalidArgumentException(sprintf('Neznámý typ služby „%s".', $serviceType));
        }
        $external = null !== $externalName && '' !== trim($externalName) ? trim($externalName) : null;
        if (!$participant instanceof Participant && !$team instanceof StaffTeam && null === $external) {
            throw new InvalidArgumentException('Přiřazení směny musí mít účastníka, tým, nebo externí jméno.');
        }
        $shift = $this->resolveShiftEvent($turnus, $serviceType, $start, $end);

        $assignment = $this->findAssignment($shift, $participant, $team, $external);
        if (!$assignment instanceof EventStaffAssignment) {
            $assignment = new EventStaffAssignment($external, $roleLabel);
            $assignment->setEvent($shift);
            $assignment->setParticipant($participant);
            $assignment->setTeam($team);
            $this->em->persist($assignment);
        } elseif (null !== $roleLabel) {
            $assignment->setRoleLabel($roleLabel);
        }
        $this->em->flush();

        return $assignment;
    }

    /**
     * Najdi nesmazaný service-Event daného typu a času přímo pod turnusem (sdílení směny → dvojice),
     * nebo založ nový. Service-eventy jsou PŘÍMÉ podakce turnusu (shodně se scoping query extension).
     */
    private function resolveShiftEvent(Event $turnus, string $serviceType, DateTimeInterface $start, DateTimeInterface $end): Event
    {
        $startKey = $start->format('Y-m-d H:i');
        $endKey = $end->format('Y-m-d H:i');
        foreach ($turnus->getSubEvents() as $sub) {
            if (null !== $sub->getDeletedAt() || $serviceType !== $sub->getCategory()?->getType()) {
                continue;
            }
            if ($sub->getStartDateTime()?->format('Y-m-d H:i') === $startKey
                && $sub->getEndDateTime()?->format('Y-m-d H:i') === $endKey) {
                return $sub;
            }
        }
        $category = $this->resolveServiceCategory($serviceType);
        $shift = new Event(new Nameable($category->getName() ?? $serviceType), superEvent: $turnus, category: $category);
        $shift->setStartDateTime(DateTime::createFromInterface($start));
        $shift->setEndDateTime(DateTime::createFromInterface($end));
        $this->em->persist($shift);

        return $shift;
    }

    /**
     * Kategorie daného service typu — běžně naseedovaná (findOneBy dle type); fallback založí
     * minimální, ať zápis neztroskotá na chybějícím číselníku (vzor: resolveBlockCategory ve web editoru).
     */
    private function resolveServiceCategory(string $serviceType): EventCategory
    {
        $category = $this->em->getRepository(EventCategory::class)->findOneBy(['type' => $serviceType]);
        if ($category instanceof EventCategory) {
            return $category;
        }
        $category = new EventCategory(new Nameable(ucfirst(str_replace('service-', '', $serviceType))), $serviceType);
        $this->em->persist($category);

        return $category;
    }

    /** Existující přiřazení téže osoby / téhož týmu / téhož externího jména na této směně (idempotence). */
    private function findAssignment(Event $shift, ?Participant $participant, ?StaffTeam $team, ?string $external): ?EventStaffAssignment
    {
        foreach ($this->em->getRepository(EventStaffAssignment::class)->findBy(['event' => $shift]) as $existing) {
            if ($participant instanceof Participant && $existing->getParticipant()?->getId() === $participant->getId()) {
                return $existing;
            }
            if ($team instanceof StaffTeam && null === $existing->getParticipant()
                && $existing->getTeam()?->getId() === $team->getId()) {
                return $existing;
            }
            if (null !== $external && null === $existing->getParticipant() && null === $existing->getTeam()
                && $existing->getExternalName() === $external) {
                return $existing;
            }
        }

        return null;
    }
}
