<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Program;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;

/**
 * Co si tým má u programu zkontrolovat, NEŽ ho zveřejní účastníkům.
 *
 * Brána zveřejnění dosud jen říkala „zveřejni, až bude hotový a zkontrolovaný“ — ale s čím
 * zkontrolovat nepomohla. Přitom program uvidí naráz stovky lidí; letos má do aplikace přístup
 * přes pět set účastníků.
 *
 * Kontroluje se JEN to, co je objektivně vadné pro účastníka, a jen u aktivit, které účastník
 * opravdu uvidí (`publicInApp`) — u týmových je poznámka v názvu v pořádku. Nálezy se nikdy
 * neopravují samy: jsou to texty týmu a přepsat je automaticky by znamenalo hádat, co tím kdo
 * myslel. Na interní sdělení má aktivita vlastní pole `internalNote`, které účastníkovi nejde.
 *
 * Že to není teoretické, ukázal import instruktorského programu 2026 — ve třech názvech ze 112
 * zůstaly interní poznámky („(jmenovky: ……)“, koncový otazník u nejisté aktivity, čas schovaný
 * v závorce). Ve zdrojovém dokumentu dávají smysl, na účastnické obrazovce ne.
 */
final readonly class ProgramReleaseCheck
{
    /**
     * Stopy interní poznámky v názvu aktivity. Záměrně úzké — falešný poplach u brány zveřejnění
     * je horší než přehlédnutý, protože po pár planých hlášeních si ji tým odvykne číst.
     *
     * @var array<string, string> regulární výraz => co s tím
     */
    private const array STOPY = [
        '/…|\.{3}/u'                          => 'nedopsané místo (…) — doplň, nebo poznámku smaž',
        '/\?\s*$/u'                           => 'končí otazníkem — vypadá jako nejistota týmu, ne jako název',
        '/\(\s*\d{1,2}[,:.]\d{2}/u'           => 'čas schovaný v závorce — patří do pole s časem, ne do názvu',
        '/\b(TODO|POZOR|DOPLNIT|xxx|XXX)\b/u' => 'interní značka v názvu',
    ];

    public function __construct(private ProgramDataService $programData)
    {
    }

    /**
     * @return list<array{aktivita: string, id: int|null, problem: string}> prázdné = není co řešit
     */
    public function problemy(Event $turnus): array
    {
        $nalezy = [];
        foreach ($this->programData->getProgramTree($turnus) as $uzel) {
            $this->projdi($uzel['activities'], $nalezy);
        }

        return $nalezy;
    }

    /**
     * Sestupuje i do slotů uvnitř bloku (rotace) — ty účastník v aplikaci vidí jako samostatné
     * položky, takže se jich týkají úplně stejná pravidla jako nadřazené aktivity.
     *
     * @param list<array<string, mixed>>                                  $aktivity
     * @param list<array{aktivita: string, id: int|null, problem: string}> $nalezy
     */
    private function projdi(array $aktivity, array &$nalezy): void
    {
        foreach ($aktivity as $aktivita) {
            if (false === ($aktivita['publicInApp'] ?? true)) {
                continue; // týmová aktivita — účastníkovi se nezobrazí, poznámka v ní nevadí
            }
            $nazev = is_string($aktivita['name'] ?? null) ? $aktivita['name'] : '';
            $id = is_int($aktivita['id'] ?? null) ? $aktivita['id'] : null;
            $podaktivity = is_array($aktivita['subActivities'] ?? null) ? $aktivita['subActivities'] : [];

            // Aktivita bez času účastníkovi nezmizí jen z členění — v aplikaci ji neuvidí vůbec,
            // protože účastnický program se skládá podle časů. A čas není povinné pole.
            // U bloku čas dodají jeho sloty, takže prázdný čas sám o sobě vadou není.
            if (null === ($aktivita['start'] ?? null) && [] === $podaktivity) {
                $nalezy[] = [
                    'aktivita' => $nazev,
                    'id'       => $id,
                    'problem'  => 'nemá čas — účastníci ji v aplikaci neuvidí vůbec',
                ];
            } else {
                foreach (self::STOPY as $vzor => $problem) {
                    if (1 === preg_match($vzor, $nazev)) {
                        $nalezy[] = ['aktivita' => $nazev, 'id' => $id, 'problem' => $problem];
                        break; // jedna hláška na aktivitu stačí, ať seznam zůstane čitelný
                    }
                }
            }

            /** @var list<array<string, mixed>> $podaktivity */
            $this->projdi($podaktivity, $nalezy);
        }
    }
}
