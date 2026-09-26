<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;

/**
 * Komu mailová skupina napíše — JEDEN výběr pro automatické rozesílání, jeho zkušební běh
 * i výpis příjemců v administraci.
 *
 * PROČ: do 26. 9. 2026 nebylo nikde vidět, komu skupina mail pošle (výtka uživatele); rozesílání
 * i zkušební běh měly výběr každý zvlášť a odkaz „účastníci podle filtru" počítal jen s filtrem.
 * Teď platí: kandidáti = přihlášky akce skupiny (i podakcí), kterým mail TOHOTO typu ještě
 * neodešel ({@see ParticipantRepository::findUnmailedParticipantIds()}); o každém rozhodnou pravidla
 * skupiny ({@see ParticipantMailGroup::duvodVyrazeni()}).
 */
final readonly class MailGroupRecipients
{
    /** Hloubka podakcí (turnusy pod ročníkem…) — stejná jako při rozesílání. */
    public const int HLOUBKA_AKCI = 4;

    public function __construct(
        private EntityManagerInterface $em,
        private ParticipantRepository $participants,
    ) {
    }

    /**
     * Kandidáti skupiny po dávkách: klíč = přihláška, hodnota = důvod vyřazení (null = mail dostane).
     *
     * Volající rozhoduje o paměti — rozesílání přihlášky potřebuje, výpis je po zpracování odpojí.
     *
     * @return \Generator<Participant, ?string>
     */
    public function kandidati(ParticipantMailGroup $group, int $davka = 100): \Generator
    {
        $event = $group->getEvent();
        $type = $group->getType();
        if (!$event instanceof Event || null === $type) {
            return;
        }
        $afterId = 0;
        while ([] !== ($ids = $this->participants->findUnmailedParticipantIds($event, $type, max(1, $davka), self::HLOUBKA_AKCI, !$group->isOnlyActive(), $afterId))) {
            $afterId = (int) end($ids);
            // Celá dávka jedním dotazem (po jedné `em->find` to bylo 2× pomalejší); pořadí podle ID
            // zůstává stejné jako dřív — rozesílání s limitem bere přihlášky od nejstarší.
            $davkaPrihlasek = $this->participants->findBy(['id' => $ids]);
            usort($davkaPrihlasek, static fn (Participant $a, Participant $b): int => $a->getId() <=> $b->getId());
            foreach ($davkaPrihlasek as $participant) {
                yield $participant => $group->duvodVyrazeni($participant);
            }
        }
    }

    /**
     * Přehled pro stránku skupiny (nic neodesílá, nic nemění).
     *
     * @return array{
     *     okno: bool,
     *     dostanou: list<array{id: int, jmeno: string, akce: string, bezUctu: bool}>,
     *     vyrazeni: array<string, list<array{id: int, jmeno: string, akce: string}>>,
     *     uzDostali: int,
     *     bezUctu: int,
     *     platnaSkupina: bool,
     * }
     */
    public function prehled(ParticipantMailGroup $group): array
    {
        $dostanou = [];
        $vyrazeni = [];
        $bezUctu = 0;
        foreach ($this->kandidati($group, 200) as $participant => $duvod) {
            $radek = [
                'id'    => (int) $participant->getId(),
                'jmeno' => $participant->getName() ?? '#'.$participant->getId(),
                'akce'  => $participant->getEvent()?->getShortName() ?? $participant->getEvent()?->getName() ?? '',
            ];
            if (null === $duvod) {
                // Mail jde na účty kontaktních osob; bez aktivovaného účtu není komu psát (nedorazí).
                $radek['bezUctu'] = 0 === $participant->getContactPersons(true)->count();
                $bezUctu += $radek['bezUctu'] ? 1 : 0;
                $dostanou[] = $radek;
            } else {
                $vyrazeni[$duvod][] = $radek;
            }
            $this->em->detach($participant);
        }
        $event = $group->getEvent();
        $type = $group->getType();

        return [
            'okno'          => $group->isApplicableByDate(),
            'dostanou'      => $dostanou,
            'vyrazeni'      => $vyrazeni,
            'uzDostali'     => $event instanceof Event && null !== $type
                ? $this->participants->countMailedParticipants($event, $type, self::HLOUBKA_AKCI, !$group->isOnlyActive())
                : 0,
            'bezUctu'       => $bezUctu,
            'platnaSkupina' => $event instanceof Event && null !== $type,
        ];
    }
}
