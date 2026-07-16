<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\CheckIn\CheckInStation;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotentně vytvoří sadu check-in stanic pro turnus (Event).
 *
 * Dva presety: `minimal` (default) = jen evidence (gate), zbytek si tým složí ve web-adminu;
 * `arrival` = celá REÁLNÁ příjezdová linka Seznamováku zrekonstruovaná z produkčních dokumentů
 * 2025 (parkování → evidence → bezpečnostní list → pásky → ubytování → balíček → zdravotník).
 *
 * Kontext: Ionic check-in hub i stůl jsou plně data-driven z {@see CheckInStation} řádků
 * ({@see \OswisOrg\OswisCalendarBundle\Controller\WebAdmin\WebAdminCheckInController} je zatím
 * pole-based Fáze A). Bez stanic hub NEUKÁŽE NIC → tenhle seed zprovozní živý flagship reálnými
 * daty, dokud (a paralelně k) web-admin konfigurační obrazovka. Stanice jsou pak plně editovatelné.
 *
 * Univerzalita (spec §2/§4): tohle je Seznamovák sada; jiná nasazení si stanice nakonfigurují jinak
 * (konference = jen evidence + jmenovka). Seed = pohodlný default, ne natvrdo daný pipeline.
 *
 * Idempotence: klíč = (event, stationKind). Existující stanice daného druhu na turnusu se přeskočí
 * (nepřepisuje se — případné ruční úpravy zůstávají). Pouštět nejdřív dry-run, pak `--apply`;
 * na prod jako web4 (Symfony CLI php8.5). Po zápisu na prod nová stanice NEMĚNÍ router → není třeba
 * rm var/cache (na rozdíl od nové API routy).
 */
#[AsCommand(
    name: 'oswis:checkin:seed-stations',
    description: 'Idempotentně vytvoří check-in stanice turnusu (--preset=minimal|arrival).',
)]
final class SeedCheckInStationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $eventRepository,
    ) {
        parent::__construct();
    }

    /**
     * Deklarativní standardní sada. `order` = pořadí dlaždic v hubu (kosmetika; evidence-gate je dán
     * druhem, ne pořadím). `capturesValue`/`valueLabel`/`valueOptions` řídí mini-dialog hodnoty u stanice.
     * `requiresOnline` u ubytování si entita stejně vynutí v setteru (sdílená kapacita).
     *
     * @return list<array{
     *   kind: string, name: string, order: int, icon: string,
     *   capturesValue: bool, valueLabel: ?string, valueOptions: list<string>|null, requiresOnline: bool
     * }>
     */
    private function stationsSpec(string $preset): array
    {
        return self::PRESET_ARRIVAL === $preset ? $this->arrivalSpec() : $this->minimalSpec();
    }

    /**
     * MINIMÁLNÍ default (user 2026-07-15): jen evidence = gate, ať hub není prázdný. Zbytek sady si tým
     * složí ve web-admin konfiguraci stanic (rozhodnutí „kolik stanic = na místě", analýza §7.2).
     * Záměrně nic nevnucujeme.
     *
     * @return list<array{kind: string, name: string, order: int, icon: string, capturesValue: bool, valueLabel: ?string, valueOptions: list<string>|null, requiresOnline: bool}>
     */
    private function minimalSpec(): array
    {
        return [
            [
                'kind' => CheckInStation::KIND_EVIDENCE, 'name' => 'Evidence', 'order' => 10,
                'icon' => 'clipboard-outline', 'capturesValue' => false, 'valueLabel' => null,
                'valueOptions' => null, 'requiresOnline' => false,
            ],
        ];
    }

    /**
     * REÁLNÁ příjezdová linka Seznamováku — zrekonstruovaná z produkčních dokumentů 2025
     * (instruktorský rozpis „PŘÍJEZDOVÝ DEN" + „Postřehy → Evidence při příjezdu"), viz
     * `docs/OSWIS_1_CHECKIN_RECONCILIATION_2026-07-15.md` §3.
     *
     * Záměrně BEZ trička a stravenek (§4): tričko = 3. den (rotace po skupinách v aule),
     * stravenky = per jídlo dle pásky (plackovač) — ani jedno není příjezdové stanoviště.
     *
     * @return list<array{kind: string, name: string, order: int, icon: string, capturesValue: bool, valueLabel: ?string, valueOptions: list<string>|null, requiresOnline: bool}>
     */
    private function arrivalSpec(): array
    {
        return [
            [
                // Před evidencí (řidiči u brány) → proto ENTRY kind, negatuje se evidencí.
                // Hodnota = SPZ (user 2026-07-16: „je nutné zapsat SPZ").
                'kind' => CheckInStation::KIND_PARKING, 'name' => 'Parkovací karty', 'order' => 10,
                'icon' => 'car-outline', 'capturesValue' => true, 'valueLabel' => 'SPZ',
                'valueOptions' => null, 'requiresOnline' => false,
            ],
            [
                'kind' => CheckInStation::KIND_EVIDENCE, 'name' => 'Evidence', 'order' => 20,
                'icon' => 'clipboard-outline', 'capturesValue' => false, 'valueLabel' => null,
                'valueOptions' => null, 'requiresOnline' => false,
            ],
            [
                'kind' => CheckInStation::KIND_SAFETY, 'name' => 'Bezpečnostní list', 'order' => 30,
                'icon' => 'shield-checkmark-outline', 'capturesValue' => false, 'valueLabel' => null,
                'valueOptions' => null, 'requiresOnline' => false,
            ],
            [
                // Barva pásky je na skupině (on-site), stanice jen odškrtne výdej.
                'kind' => CheckInStation::KIND_WRISTBAND, 'name' => 'Pásky', 'order' => 40,
                'icon' => 'ellipse-outline', 'capturesValue' => false, 'valueLabel' => null,
                'valueOptions' => null, 'requiresOnline' => false,
            ],
            [
                // Hodnota = číslo pokoje/chatky (fallback, dokud není přiřazování proti Accommodation modelu).
                'kind' => CheckInStation::KIND_ACCOMMODATION, 'name' => 'Ubytování', 'order' => 50,
                'icon' => 'bed-outline', 'capturesValue' => true, 'valueLabel' => 'Pokoj / chatka',
                'valueOptions' => null, 'requiresOnline' => true,
            ],
            [
                'kind' => CheckInStation::KIND_WELCOME, 'name' => 'Příjezdový balíček', 'order' => 60,
                'icon' => 'gift-outline', 'capturesValue' => false, 'valueLabel' => null,
                'valueOptions' => null, 'requiresOnline' => false,
            ],
            [
                // Read-view diet — dietáře vede rovnou za kuchařku; „hotovo" = probráno.
                'kind' => CheckInStation::KIND_MEDIC, 'name' => 'Zdravotník / diety', 'order' => 70,
                'icon' => 'medkit-outline', 'capturesValue' => false, 'valueLabel' => null,
                'valueOptions' => null, 'requiresOnline' => false,
            ],
        ];
    }

    /** Jen evidence (gate) — tým si zbytek složí ve web-adminu. */
    public const string PRESET_MINIMAL = 'minimal';

    /** Celá zrekonstruovaná příjezdová linka Seznamováku ({@see arrivalSpec}). */
    public const string PRESET_ARRIVAL = 'arrival';

    protected function configure(): void
    {
        $this->addArgument('eventSlug', InputArgument::REQUIRED, 'Slug turnusu (Event), na který se stanice vytvoří.');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Skutečně zapsat (bez tohoto jen dry-run).');
        $this->addOption(
            'preset',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf(
                '"%s" = jen evidence (default), "%s" = celá reálná příjezdová linka Seznamováku (parkování, evidence, bezpečnost, pásky, ubytování, balíček, zdravotník).',
                self::PRESET_MINIMAL,
                self::PRESET_ARRIVAL,
            ),
            self::PRESET_MINIMAL,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $eventSlug = $input->getArgument('eventSlug');
        if (!is_string($eventSlug) || '' === $eventSlug) {
            $io->error('Argument eventSlug musí být neprázdný slug turnusu.');

            return Command::FAILURE;
        }
        $apply = true === $input->getOption('apply');
        $preset = $input->getOption('preset');
        if (!in_array($preset, [self::PRESET_MINIMAL, self::PRESET_ARRIVAL], true)) {
            $io->error(sprintf('Neznámý preset — použij "%s" nebo "%s".', self::PRESET_MINIMAL, self::PRESET_ARRIVAL));

            return Command::FAILURE;
        }

        $event = $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug]);
        if (!$event instanceof Event) {
            $io->error("Turnus se slugem \"{$eventSlug}\" nenalezen.");

            return Command::FAILURE;
        }

        $io->title('Seed check-in stanic — turnus: '.((string) $event->getName()).' ('.$eventSlug.')');
        $io->writeln('Režim: '.($apply ? '<comment>APPLY (zápis)</comment>' : 'dry-run (jen výpis)').', preset: <info>'.$preset.'</info>');

        $repo = $this->em->getRepository(CheckInStation::class);
        $created = 0;
        $skipped = 0;
        $rows = [];
        foreach ($this->stationsSpec($preset) as $spec) {
            $existing = $repo->findOneBy(['event' => $event, 'stationKind' => $spec['kind']]);
            if ($existing instanceof CheckInStation && !$existing->isDeleted()) {
                ++$skipped;
                $rows[] = [$spec['order'], $spec['kind'], $spec['name'], '= existuje (přeskočeno)'];
                continue;
            }
            ++$created;
            $value = $spec['capturesValue']
                ? ('hodnota: '.((string) $spec['valueLabel']).(null !== $spec['valueOptions'] ? ' ['.implode('/', $spec['valueOptions']).']' : ' [volný text]'))
                : '—';
            $rows[] = [$spec['order'], $spec['kind'], $spec['name'], '+ nová '.($spec['requiresOnline'] ? '(online) ' : '').$value];

            if ($apply) {
                $forcedSlug = $spec['kind'].'-'.$eventSlug;
                $station = new CheckInStation(
                    new Nameable($spec['name'], $spec['name'], null, null, $forcedSlug),
                    $spec['kind'],
                    $spec['order'],
                );
                $station->setEvent($event);
                $station->setIcon($spec['icon']);
                $station->setCapturesValue($spec['capturesValue']);
                $station->setValueLabel($spec['valueLabel']);
                $station->setValueOptions($spec['valueOptions']);
                $station->setRequiresOnline($spec['requiresOnline']);
                $this->em->persist($station);
            }
        }

        $io->table(['#', 'druh', 'název', 'akce'], $rows);

        if ($apply) {
            $this->em->flush();
            $io->success("Zapsáno: {$created} nových, {$skipped} přeskočeno. Ověř doctrine:schema:validate + hub v prohlížeči.");
        } else {
            $io->note("Dry-run: {$created} by se vytvořilo, {$skipped} už existuje. Spusť znovu s --apply pro zápis.");
        }

        return Command::SUCCESS;
    }
}
