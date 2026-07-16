<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationFeature;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationUnit;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Bed;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Facility;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Naseeduje ubytovací kapacity **Morava Campu** — jediného kempu, kde Seznamovák probíhá.
 *
 * Zdroj dat: `docs/seznamovak-documents/2025 ubytovací list.xlsx` (reálný ubytovací arch, který tým
 * dnes vyplňuje ručně v Excelu). Struktura potvrzená userem 2026-07-16:
 * - **Jeden kemp = jedna {@see Facility}**; „Motel / chatky / mobilheimy / karavan" NEJSOU samostatná
 *   zařízení, ale členění uvnitř téhož kempu → jdou do `unitType` jednotky.
 * - **Přistýlka = další postel v pokoji**, ale doplňková a méně preferovaná (rozkládací gauč apod.)
 *   → {@see Bed::TYPE_EXTRA} s popiskem, ne zvláštní pole na jednotce.
 * - **Manželské postele = jen informativně** (preferované pro páry) → dvě lůžka spárovaná přes
 *   `Bed::pairedWith`. Dle archu jsou jen v třílůžkových pokojích a apartmánech (107–110, 207–210).
 * - **ZTP = informativně u pokoje** („je bezbariérový") → {@see AccommodationFeature} s kódem `ztp`,
 *   ne příznak účastníka.
 * - **Vlastní stan** = účastník si ho přiveze → sekce „Stan" z archu se NESEEDUJE (kemp tam nic
 *   nepůjčuje, není co přiřazovat; nese to příznak typu ubytování na přihlášce).
 *
 * Typ ubytování z přihlášky říká, kam člověk patří: hotelové → Motel, běžné → ostatní pokoje
 * a chatky, vlastní stan → mimo tenhle seznam.
 *
 * Idempotence: klíč = (facility, název jednotky). Existující jednotka se přeskočí i s lůžky —
 * ruční úpravy týmu zůstanou. Nejdřív dry-run, pak `--apply`.
 */
#[AsCommand(
    name: 'oswis:accommodation:seed-morava-camp',
    description: 'Naseeduje ubytovací kapacity Morava Campu z reálného ubytovacího archu (dry-run; --apply zapíše).',
)]
final class SeedMoravaCampCommand extends Command
{
    private const string FACILITY_SLUG = 'morava-camp';

    /** Vlastnosti (informativní) — kód => název. */
    private const array FEATURES = [
        'wc' => 'Vlastní WC',
        'shower' => 'Vlastní sprcha',
        'tv' => 'TV',
        'own_bathroom' => 'Vlastní sociální zařízení',
        'shared_bathroom' => 'Společné sociální zařízení',
        'ztp' => 'Bezbariérový',
        'double_bed' => 'Manželská postel',
        'apartment' => 'Apartmá',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    /**
     * Sekce kempu → jednotky. `beds` = počet běžných lůžek, `extra` = přistýlka navíc,
     * `double` = spárovat dvě lůžka jako manželskou postel (informativně).
     *
     * @return list<array{type: string, name: string, features: list<string>, units: list<array{name: string, beds: int, extra: bool, floor: ?string, double: bool, features: list<string>}>}>
     */
    private function sections(): array
    {
        return [
            [
                'type' => 'motel',
                'name' => 'Motel',
                'features' => ['wc', 'shower', 'tv'],
                'units' => [
                    // Přízemí. 106 a 206 arch NEUVÁDÍ kapacitu → vynechány (viz poznámka v execute()).
                    $this->unit('101', 2, true, '1'),
                    $this->unit('102', 2, true, '1'),
                    $this->unit('103', 2, true, '1'),
                    $this->unit('104', 2, true, '1'),
                    $this->unit('105', 2, true, '1', false, ['apartment']),
                    $this->unit('107', 3, true, '1', true),
                    $this->unit('108', 3, true, '1', true),
                    $this->unit('109', 3, false, '1', true),
                    $this->unit('110', 3, false, '1', true),
                    $this->unit('111', 2, true, '1'),
                    $this->unit('112', 2, true, '1'),
                    $this->unit('113', 2, true, '1'),
                    $this->unit('114', 2, false, '1'),
                    $this->unit('115', 2, false, '1'),
                    $this->unit('116', 2, false, '1', false, ['ztp']),
                    // Patro („po schodech nahoru").
                    $this->unit('201', 2, false, '2'),
                    $this->unit('202', 2, false, '2'),
                    $this->unit('203', 2, true, '2'),
                    $this->unit('204', 2, false, '2'),
                    $this->unit('205', 2, true, '2', false, ['apartment']),
                    $this->unit('207', 3, true, '2', true),
                    $this->unit('208', 3, true, '2', true),
                    $this->unit('209', 3, false, '2', true),
                    $this->unit('210', 3, false, '2', true),
                    $this->unit('211', 2, true, '2'),
                    $this->unit('212', 2, true, '2'),
                    $this->unit('213', 2, false, '2'),
                    $this->unit('214', 2, false, '2'),
                    $this->unit('215', 2, false, '2'),
                    $this->unit('216', 4, false, '2'),
                ],
            ],
            [
                'type' => 'chata-sk',
                'name' => 'Chaty SK',
                'features' => ['shared_bathroom'],
                'units' => [
                    $this->unit('SK 41', 4, false), $this->unit('SK 42', 4, false),
                    $this->unit('SK 43', 4, false), $this->unit('SK 44', 4, false),
                    $this->unit('SK 45', 4, false), $this->unit('SK 46', 4, false),
                    $this->unit('SK 47', 4, false), $this->unit('SK 48', 5, false),
                    $this->unit('SK 49', 6, false),
                ],
            ],
            [
                'type' => 'chata-bobik',
                'name' => 'Chaty Bobík',
                'features' => ['own_bathroom'],
                'units' => [
                    $this->unit('BO 11', 4, true), $this->unit('BO 12', 4, true),
                    $this->unit('BO 13', 4, true), $this->unit('BO 14', 4, true),
                ],
            ],
            [
                'type' => 'chata-richor',
                'name' => 'Chaty Richor',
                'features' => ['shared_bathroom'],
                'units' => [
                    $this->unit('RI 50', 4, false), $this->unit('RI 51', 4, false),
                    $this->unit('RI 52', 4, false), $this->unit('RI 53', 4, false),
                ],
            ],
            [
                'type' => 'chata-muller',
                'name' => 'Chaty Müller',
                'features' => ['shared_bathroom'],
                'units' => [
                    $this->unit('MÜ 54', 4, false), $this->unit('MÜ 55', 4, false),
                    $this->unit('MÜ 56', 4, false), $this->unit('MÜ 57', 4, false),
                    $this->unit('MÜ 58', 4, false), $this->unit('MÜ 59', 4, false),
                ],
            ],
            [
                'type' => 'chata-srub',
                'name' => 'Chaty Sruby',
                'features' => ['shared_bathroom'],
                'units' => array_map(fn (int $n): array => $this->unit("SR {$n}", 2, false), range(60, 80)),
            ],
            [
                'type' => 'chata-eliska',
                'name' => 'Chaty Eliška',
                'features' => ['shared_bathroom'],
                'units' => [$this->unit('Eliška 1', 2, false), $this->unit('Eliška 2', 2, false)],
            ],
            [
                'type' => 'mobilheim',
                'name' => 'Mobilheimy',
                'features' => ['own_bathroom'],
                'units' => [$this->unit('Mobilheim 1', 4, false), $this->unit('Mobilheim 2', 6, true)],
            ],
            [
                'type' => 'karavan',
                'name' => 'Karavan',
                'features' => [],
                'units' => [$this->unit('Karavan', 4, false)],
            ],
            [
                'type' => 'chata-lucie',
                'name' => 'Chata Lucie',
                'features' => ['shared_bathroom'],
                'units' => [$this->unit('Lucie', 4, false)],
            ],
            [
                'type' => 'ubytovna',
                'name' => 'Ubytovny A+C',
                'features' => ['shared_bathroom'],
                'units' => array_merge(
                    array_map(fn (int $n): array => $this->unit("A {$n}", 2, false), range(1, 6)),
                    array_map(fn (int $n): array => $this->unit("C {$n}", 2, false), range(7, 21)),
                ),
            ],
        ];
    }

    /**
     * @param list<string> $features
     *
     * @return array{name: string, beds: int, extra: bool, floor: ?string, double: bool, features: list<string>}
     */
    private function unit(string $name, int $beds, bool $extra, ?string $floor = null, bool $double = false, array $features = []): array
    {
        return ['name' => $name, 'beds' => $beds, 'extra' => $extra, 'floor' => $floor, 'double' => $double, 'features' => $features];
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Skutečně zapsat (bez tohoto jen dry-run).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = true === $input->getOption('apply');

        $io->title('Seed ubytovacích kapacit — Morava Camp');
        $io->writeln('Režim: '.($apply ? '<comment>APPLY (zápis)</comment>' : 'dry-run (jen výpis)'));
        $io->note('Zdroj: docs/seznamovak-documents/2025 ubytovací list.xlsx. Pokoje 106 a 206 arch neuvádí (bez kapacity) → vynechány. Sekce „Stan" se neseeduje (vlastní stany účastníků).');

        $facility = $this->resolveFacility($apply);
        $features = $this->resolveFeatures($apply);

        $unitRepo = $this->em->getRepository(AccommodationUnit::class);
        $created = 0;
        $skipped = 0;
        $bedsTotal = 0;
        $rows = [];

        foreach ($this->sections() as $section) {
            foreach ($section['units'] as $spec) {
                $existing = null;
                if (null !== $facility) {
                    $existing = $unitRepo->findOneBy(['facility' => $facility, 'name' => $spec['name']]);
                }
                if ($existing instanceof AccommodationUnit) {
                    ++$skipped;
                    continue;
                }
                ++$created;
                $capacity = $spec['beds'] + ($spec['extra'] ? 1 : 0);
                $bedsTotal += $capacity;
                $rows[] = [
                    $section['name'],
                    $spec['name'],
                    (string) $spec['beds'].($spec['extra'] ? ' + přistýlka' : ''),
                    (string) $capacity,
                    implode(', ', array_merge($section['features'], $spec['features'], $spec['double'] ? ['double_bed'] : [])),
                ];

                if ($apply && null !== $facility) {
                    $this->createUnit($facility, $section, $spec, $features);
                }
            }
        }

        $io->table(['sekce', 'jednotka', 'lůžka', 'kapacita', 'vlastnosti'], $rows);

        if ($apply) {
            $this->em->flush();
            $io->success("Zapsáno: {$created} jednotek ({$bedsTotal} lůžek), {$skipped} už existovalo. Ověř doctrine:schema:validate.");
        } else {
            $io->note("Dry-run: {$created} jednotek ({$bedsTotal} lůžek) by se vytvořilo, {$skipped} už existuje. Spusť s --apply.");
        }

        return Command::SUCCESS;
    }

    private function resolveFacility(bool $apply): ?Facility
    {
        $repo = $this->em->getRepository(Facility::class);
        $facility = $repo->findOneBy(['slug' => self::FACILITY_SLUG]);
        if ($facility instanceof Facility) {
            return $facility;
        }
        if (!$apply) {
            return null;
        }
        $facility = new Facility(
            new Nameable('Morava Camp', 'Morava Camp', null, null, self::FACILITY_SLUG),
            Facility::TYPE_CAMP,
        );
        $this->em->persist($facility);

        return $facility;
    }

    /**
     * @return array<string, AccommodationFeature>
     */
    private function resolveFeatures(bool $apply): array
    {
        $repo = $this->em->getRepository(AccommodationFeature::class);
        $map = [];
        foreach (self::FEATURES as $code => $name) {
            $feature = $repo->findOneBy(['code' => $code]);
            if (!$feature instanceof AccommodationFeature && $apply) {
                $feature = new AccommodationFeature(new Nameable($name), $code);
                $this->em->persist($feature);
            }
            if ($feature instanceof AccommodationFeature) {
                $map[$code] = $feature;
            }
        }

        return $map;
    }

    /**
     * @param array{type: string, name: string, features: list<string>, units: list<mixed>} $section
     * @param array{name: string, beds: int, extra: bool, floor: ?string, double: bool, features: list<string>} $spec
     * @param array<string, AccommodationFeature> $features
     */
    private function createUnit(Facility $facility, array $section, array $spec, array $features): void
    {
        $unit = new AccommodationUnit(
            new Nameable($spec['name']),
            $section['type'],
            $spec['beds'] + ($spec['extra'] ? 1 : 0),
        );
        $unit->setFacility($facility);
        $unit->setFloor($spec['floor']);
        foreach (array_merge($section['features'], $spec['features'], $spec['double'] ? ['double_bed'] : []) as $code) {
            if (isset($features[$code])) {
                $unit->addFeature($features[$code]);
            }
        }
        $this->em->persist($unit);

        $created = [];
        for ($i = 1; $i <= $spec['beds']; ++$i) {
            $bed = new Bed("Lůžko {$i}", Bed::TYPE_SINGLE);
            $bed->setUnit($unit);
            $this->em->persist($bed);
            $created[] = $bed;
        }
        // Manželská postel = dvě lůžka spárovaná; jen informace pro páry, kapacitu nemění.
        if ($spec['double'] && count($created) >= 2) {
            $created[0]->setPairedWith($created[1]);
            $created[1]->setPairedWith($created[0]);
        }
        // Přistýlka je plnohodnotné lůžko, ale doplňkové a méně preferované → vlastní typ + popisek.
        if ($spec['extra']) {
            $extra = new Bed('Přistýlka', Bed::TYPE_EXTRA);
            $extra->setUnit($unit);
            $this->em->persist($extra);
        }
    }
}
