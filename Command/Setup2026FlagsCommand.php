<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagAmountRange;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Price;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagGroupOffer;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotentně doplní admin-only příznakové kategorie na hlavní přihlášku ročníku 2026:
 *  - Stravovací omezení (8-volbová merge sada; "Jiné" s textovým upřesněním),
 *  - Doprava – příjezd / odjezd (po 1 příznaku s textValem = datum a čas).
 *
 * Flag konfigurace se v OSWISu tvoří přes entity vrstvu (viz YearCloneService) — tento command
 * mirroruje stejnou konstrukci. Pouštět nejdřív na klon (--apply), pak na prod jako web4.
 */
#[AsCommand(
    name: 'oswis:flags:setup-2026',
    description: 'Idempotentně naváže admin kategorie příznaků (dietní + doprava) na hlavní přihlášku ročníku.',
)]
final class Setup2026FlagsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    /**
     * Deklarativní specifikace kategorií k zajištění. Slugy příznaků dietních režimů a dopravy
     * odpovídají existujícím master příznakům (ověřeno v DB) — ty se NEpřejmenovávají, jen se na ně
     * vytvoří nabídky. Nové dietní příznaky (vejce/sója/ořechy/jiné) se vytvoří.
     *
     * `categoryName`/`categoryType` jsou volitelné — uvádí je jen spec, který smí kategorii i ZALOŽIT
     * (jinak platí „neexistuje → přeskoč", ať nevznikají data naslepo).
     *
     * @return list<array{
     *   categorySlug: string, categoryName?: string, categoryType?: string,
     *   groupOfferSlug: string, groupName: string, groupShortName: string,
     *   min: int, max: int|null,
     *   flags: list<array{slug: string, name: string, shortName: string, formValueLabel: ?string}>
     * }>
     */
    private function categoriesSpec(): array
    {
        return [
            [
                'categorySlug'   => 'stravovaci-omezeni',
                'groupOfferSlug' => 'stravovaci-omezeni-2026',
                'groupName'      => 'Stravovací omezení',
                'groupShortName' => 'Strava',
                'min'            => 0,
                'max'            => null, // multi-select
                'flags'          => [
                    ['slug' => 'vegetarian', 'name' => 'Vegetariánská strava', 'shortName' => 'Vegetarián', 'formValueLabel' => null],
                    ['slug' => 'vegan', 'name' => 'Veganská strava', 'shortName' => 'Vegan', 'formValueLabel' => null],
                    ['slug' => 'bez-lepku', 'name' => 'Lepek', 'shortName' => 'Lepek', 'formValueLabel' => null],
                    ['slug' => 'bez-laktozy', 'name' => 'Mléko/laktóza', 'shortName' => 'Mléko/laktóza', 'formValueLabel' => null],
                    ['slug' => 'vejce', 'name' => 'Vejce', 'shortName' => 'Vejce', 'formValueLabel' => null],
                    ['slug' => 'soja', 'name' => 'Sója', 'shortName' => 'Sója', 'formValueLabel' => null],
                    ['slug' => 'orechy', 'name' => 'Ořechy', 'shortName' => 'Ořechy', 'formValueLabel' => null],
                    ['slug' => 'jine-dieta', 'name' => 'Jiné (upřesněte)', 'shortName' => 'Jiné', 'formValueLabel' => 'Upřesnění (alergie/dieta)'],
                ],
            ],
            [
                // Parkování v areálu — příznaky BEZ CENY. Poplatek (2026: 200 Kč) jde do JINÉ KASY než
                // platby za přihlášky, takže nabídka NESMÍ mít cenu: spadla by přes getFlagsPrice()
                // do ceny přihlášky, remainingPrice i párování plateb (user 2026-07-16). Částka se
                // needviduje vůbec — stačí, že zaplatil; obsah kasy = počet příznaků × poplatek.
                //
                // DVA příznaky, protože jde o dva různé údaje o TÉŽE přihlášce (max 2):
                //   • parkuje-v-arealu → hodnota SPZ (vlastní auto účastníka),
                //   • parkovaci-karta  → hodnota číslo karty ZAPŮJČENÉ od kempu (majetek kempu, vrací se).
                // Karta patří na PŘIHLÁŠKU, ne jen na průchod stanicí (user 16.7., závazné): u průchodu
                // by přežila jen jako historie události u stolu a nešla by najít u člověka, kterého
                // řeším. Až vznikne modul zápůjček (D9, deskovky/sporty), je tohle jeho první případ
                // a údaj se přestěhuje — proto samostatný příznak, ne přilepení k SPZ.
                //
                // Obojí zapisuje výhradně stůl: skupina i nabídky mají publicOnWeb=false, takže se
                // v registračním formuláři nenabídnou (FlagGroupOfParticipantType je přeskočí) —
                // účastník je vyplnit nemůže, ale mezi svými příznaky je vidí (user 16.7.).
                'categorySlug'   => 'parkovani',
                'categoryType'   => RegistrationFlagCategory::TYPE_PARKING,
                'categoryName'   => 'Parkování v areálu',
                'groupOfferSlug' => 'parkovani-2026',
                'groupName'      => 'Parkování v areálu',
                'groupShortName' => 'Parkování',
                'min'            => 0,
                'max'            => 2,
                'flags'          => [
                    ['slug' => 'parkuje-v-arealu', 'name' => 'Parkuje v areálu (poplatek zaplacen)', 'shortName' => 'Parkuje', 'formValueLabel' => 'SPZ'],
                    ['slug' => 'parkovaci-karta', 'name' => 'Zapůjčená parkovací karta', 'shortName' => 'Parkovací karta', 'formValueLabel' => 'Číslo karty'],
                ],
            ],
            [
                'categorySlug'   => 'doprava-prijezd',
                'groupOfferSlug' => 'doprava-prijezd-2026',
                'groupName'      => 'Doprava – příjezd',
                'groupShortName' => 'Příjezd',
                'min'            => 0,
                'max'            => 1, // binární
                'flags'          => [
                    ['slug' => 'odvoz-do-kempu', 'name' => 'Odvoz z nádraží do kempu', 'shortName' => 'Odvoz z nádraží', 'formValueLabel' => 'Datum a čas příjezdu'],
                ],
            ],
            [
                'categorySlug'   => 'doprava-odjezd',
                'groupOfferSlug' => 'doprava-odjezd-2026',
                'groupName'      => 'Doprava – odjezd',
                'groupShortName' => 'Odjezd',
                'min'            => 0,
                'max'            => 1, // binární
                'flags'          => [
                    ['slug' => 'odjezd-vlakem', 'name' => 'Odvoz z kempu na nádraží', 'shortName' => 'Odvoz na nádraží', 'formValueLabel' => 'Datum a čas odjezdu'],
                ],
            ],
        ];
    }

    protected function configure(): void
    {
        $this->addArgument('regOfferId', InputArgument::REQUIRED, 'ID hlavní přihlášky ročníku (calendar_reg_range.id; na klonu 120 pro 2026)');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Skutečně zapsat změny (bez tohoto jen dry-run).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $regOfferIdRaw = $input->getArgument('regOfferId');
        if (!is_string($regOfferIdRaw) || !ctype_digit($regOfferIdRaw)) {
            $io->error('Argument regOfferId musí být celé číslo (ID přihlášky).');

            return Command::FAILURE;
        }
        $regOfferId = (int) $regOfferIdRaw;
        $apply = true === $input->getOption('apply');

        $offer = $this->em->getRepository(RegistrationOffer::class)->find($regOfferId);
        if (!$offer instanceof RegistrationOffer) {
            $io->error("Přihláška id={$regOfferId} neexistuje.");

            return Command::FAILURE;
        }

        $io->title('Setup příznaků — přihláška: '.((string) $offer->getName()).' (id '.$regOfferId.')');
        $io->writeln('Režim: '.($apply ? '<comment>APPLY (zápis)</comment>' : 'dry-run (jen výpis)'));

        foreach ($this->categoriesSpec() as $spec) {
            $category = $this->em->getRepository(RegistrationFlagCategory::class)->findOneBy(['slug' => $spec['categorySlug']]);
            if (!$category instanceof RegistrationFlagCategory) {
                // Novou kategorii založíme jen tehdy, když spec říká, JAKÁ má být (název + typ).
                // U ostatních zůstává chování „neexistuje → přeskoč" (nevymýšlet data naslepo).
                if (!isset($spec['categoryName'], $spec['categoryType'])) {
                    $io->error("Kategorie \"{$spec['categorySlug']}\" nenalezena — přeskakuji.");
                    continue;
                }
                $io->writeln("  + zakládám kategorii \"{$spec['categorySlug']}\" (typ {$spec['categoryType']})");
                if ($apply) {
                    $category = new RegistrationFlagCategory(
                        new Nameable($spec['categoryName'], $spec['categoryName'], null, null, $spec['categorySlug']),
                        $spec['categoryType'],
                    );
                    $this->em->persist($category);
                } else {
                    continue;
                }
            }
            $io->section($spec['groupName']." ({$spec['categorySlug']})");
            $flags = $this->syncMasterFlags($category, $spec['flags'], $io, $apply);
            $this->buildGroupOffer($offer, $category, $spec, $flags, $io, $apply);
        }

        if ($apply) {
            $this->em->flush();
            $io->success('Zapsáno. Spusť doctrine:schema:validate a ověř editor v prohlížeči.');
        } else {
            $io->note('Dry-run hotov. Spusť znovu s --apply pro zápis.');
        }

        return Command::SUCCESS;
    }

    /**
     * Zajistí master příznaky (idempotentně): vytvoří chybějící, přejmenuje ty, jejichž název neodpovídá.
     * Slug se vždy drží stabilní (data + filtr fasety). Vrací slug => RegistrationFlag.
     *
     * @param list<array{slug: string, name: string, shortName: string, formValueLabel: ?string}> $flagSpecs
     *
     * @return array<string, RegistrationFlag>
     */
    private function syncMasterFlags(RegistrationFlagCategory $category, array $flagSpecs, SymfonyStyle $io, bool $apply): array
    {
        $repo = $this->em->getRepository(RegistrationFlag::class);
        $result = [];
        foreach ($flagSpecs as $f) {
            $flag = $repo->findOneBy(['slug' => $f['slug']]);
            if ($flag instanceof RegistrationFlag) {
                if ($flag->getName() !== $f['name'] || $flag->getShortName() !== $f['shortName']) {
                    $io->writeln(sprintf('  ~ rename "%s": %s → %s', $f['slug'], (string) $flag->getName(), $f['name']));
                    if ($apply) {
                        $flag->setName($f['name']);
                        $flag->setShortName($f['shortName']);
                        $flag->setForcedSlug($f['slug']); // drž slug i po změně názvu
                    }
                } else {
                    $io->writeln(sprintf('  = "%s" beze změny', $f['slug']));
                }
                $result[$f['slug']] = $flag;
                continue;
            }
            $io->writeln(sprintf('  + nový příznak "%s" (%s)', $f['slug'], $f['name']));
            $flag = new RegistrationFlag(new Nameable($f['name'], $f['shortName'], null, null, $f['slug']), $category);
            if ($apply) {
                $this->em->persist($flag);
            }
            $result[$f['slug']] = $flag;
        }

        return $result;
    }

    /**
     * Vytvoří (pokud chybí) group offer pro daný ročník + flag offers (admin-only, zdarma, bez kapacity)
     * a naváže group offer na přihlášku.
     *
     * Idempotentní, ale NE „všechno nebo nic": existující group offer se DOROVNÁ na specifikaci
     * ({@see reconcileGroupOffer()}) — přidají se chybějící flag offers a srovná se min/max. Dřív se
     * celá skupina přeskočila, což tiše zahodilo každou pozdější změnu specifikace: přidání příznaku
     * `parkovaci-karta` (16.7.) by se na existující instalaci NIKDY neprojevilo a `max` by zůstal 1,
     * takže by zápis obou příznaků spadl na validaci. Ticho je tu horší než práce navíc.
     *
     * @param array{
     *   categorySlug: string, groupOfferSlug: string, groupName: string, groupShortName: string,
     *   min: int, max: int|null,
     *   flags: list<array{slug: string, name: string, shortName: string, formValueLabel: ?string}>
     * } $spec
     * @param array<string, RegistrationFlag> $flags
     */
    private function buildGroupOffer(
        RegistrationOffer $offer,
        RegistrationFlagCategory $category,
        array $spec,
        array $flags,
        SymfonyStyle $io,
        bool $apply,
    ): void {
        foreach ($offer->getFlagGroupRanges() as $existing) {
            if ($spec['groupOfferSlug'] === $existing->getSlug()) {
                $this->reconcileGroupOffer($existing, $spec, $flags, $io, $apply);

                return;
            }
        }

        $io->writeln('  + group offer "'.$spec['groupOfferSlug'].'" (admin-only)');
        $group = new RegistrationFlagGroupOffer($category, new FlagAmountRange($spec['min'], $spec['max']), null, null);
        $group->setName($spec['groupName']);
        $group->setShortName($spec['groupShortName']);
        $group->setForcedSlug($spec['groupOfferSlug']);
        $group->setPublicOnWeb(false); // admin-set: mimo registrační formulář, editor (onlyPublic=false) ho nabídne

        foreach ($spec['flags'] as $f) {
            $io->writeln('    + flag offer "'.$f['slug'].'-2026"');
            $group->addFlagRange($this->createFlagOffer($f, $flags[$f['slug']], $apply));
        }

        $offer->addFlagGroupRange($group);
        if ($apply) {
            $this->em->persist($group);
        }
    }

    /**
     * Dorovná JIŽ EXISTUJÍCÍ group offer na specifikaci: přidá chybějící flag offers a srovná min/max.
     *
     * Záměrně NEsahá na to, co už existuje (názvy, ceny, hodnoty u přihlášek) — přejmenování je
     * legitimní adminova změna a command ji nesmí přepsat. Řeší jen to, co ve specifikaci PŘIBYLO.
     * Min/max se srovnat MUSÍ: bez toho by nový příznak sice existoval, ale nešel zapsat (validace
     * skupiny) — což je přesně ten tichý polovičatý stav, kvůli kterému tahle metoda vznikla.
     *
     * @param array{
     *   categorySlug: string, groupOfferSlug: string, groupName: string, groupShortName: string,
     *   min: int, max: int|null,
     *   flags: list<array{slug: string, name: string, shortName: string, formValueLabel: ?string}>
     * } $spec
     * @param array<string, RegistrationFlag> $flags
     */
    private function reconcileGroupOffer(
        RegistrationFlagGroupOffer $group,
        array $spec,
        array $flags,
        SymfonyStyle $io,
        bool $apply,
    ): void {
        $existingSlugs = [];
        foreach ($group->getFlagOffers(false) as $existingOffer) {
            $existingSlugs[] = $existingOffer->getSlug();
        }

        $added = 0;
        foreach ($spec['flags'] as $f) {
            $offerSlug = $f['slug'].'-2026';
            if (in_array($offerSlug, $existingSlugs, true)) {
                continue;
            }
            $io->writeln('    + flag offer "'.$offerSlug.'" (doplněn do existující skupiny)');
            $group->addFlagRange($this->createFlagOffer($f, $flags[$f['slug']], $apply));
            ++$added;
        }

        $minMaxChanged = $group->getMin() !== $spec['min'] || $group->getMax() !== $spec['max'];
        if ($minMaxChanged) {
            $io->writeln(
                '    ~ min/max '.$group->getMin().'/'.($group->getMax() ?? '∞')
                .' → '.$spec['min'].'/'.($spec['max'] ?? '∞'),
            );
            $group->setFlagAmountRange(new FlagAmountRange($spec['min'], $spec['max']));
        }

        if (0 === $added && !$minMaxChanged) {
            $io->writeln('  = group offer "'.$spec['groupOfferSlug'].'" už odpovídá specifikaci.');
        } else {
            $io->writeln('  ~ group offer "'.$spec['groupOfferSlug'].'" dorovnán.');
        }
    }

    /**
     * Admin-only nabídka příznaku: zdarma, bez kapacitního stropu, mimo registrační formulář.
     *
     * @param array{slug: string, name: string, shortName: string, formValueLabel: ?string} $f
     */
    private function createFlagOffer(array $f, RegistrationFlag $flag, bool $apply): RegistrationFlagOffer
    {
        $flagOffer = new RegistrationFlagOffer(
            $flag,
            // Bez kapacitního stropu — admin dietní/dopravní příznaky nejsou omezený zdroj.
            // null tu funguje díky RegistrationFlagOffer::setBaseCapacity(), který (na rozdíl
            // od CapacityTrait) zachová null → příznak nemá čítač a jde vždy přiřadit.
            new Capacity(null, null),
            new Price(0, 0),          // zdarma, bez zálohy
            new FlagAmountRange(0, null),
            null,
        );
        $flagOffer->setName($f['name']);
        $flagOffer->setShortName($f['shortName']);
        $flagOffer->setForcedSlug($f['slug'].'-2026');
        $flagOffer->setPublicOnWeb(false);
        $flagOffer->setBaseUsage(0);
        $flagOffer->setFullUsage(0);
        if (null !== $f['formValueLabel']) {
            $flagOffer->setFormValueLabel($f['formValueLabel']);
            $flagOffer->setFormValueRegex('.{0,255}'); // povolí volný text → isFormValueAllowed()=true
        }
        if ($apply) {
            $this->em->persist($flagOffer);
        }

        return $flagOffer;
    }
}
