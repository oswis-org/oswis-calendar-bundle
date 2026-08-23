<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\NonPersistent;

class Capacity
{
    public ?int $baseCapacity = null;

    public ?int $fullCapacity = null;

    public function __construct(?int $baseCapacity = null, ?int $fullCapacity = null)
    {
        $this->setCapacity($baseCapacity, $fullCapacity);
    }

    /**
     * Nastaví dvojici kapacit se vzájemným doplněním.
     *
     * ⚠️ Chybí-li ZÁKLADNÍ kapacita, ale plná je vyplněná, platí plná i jako základní.
     * Dřív se v takovém případě zahodily OBĚ — kdo v administraci vyplnil jen „plná kapacita",
     * dostal z API `null`, kontrola „plno" se nikdy neuplatnila a aktivita šla přeplnit bez
     * jediné hlášky (naráženo 22. 8. 2026 u Lukostřelby: `full_capacity = 10` v databázi,
     * v API `null`). Tiché zahození zadané hodnoty je horší než jakákoli interpretace.
     *
     * Obě prázdné = bez stropu; to zůstává.
     */
    public function setCapacity(?int $baseCapacity = null, ?int $fullCapacity = null): void
    {
        if (null === $baseCapacity && null === $fullCapacity) {
            $this->baseCapacity = null;
            $this->fullCapacity = null;

            return;
        }
        if (null === $baseCapacity) {
            $baseCapacity = $fullCapacity;
        }
        if (null === $fullCapacity) {
            $this->baseCapacity = $baseCapacity;
            $this->fullCapacity = null;

            return;
        }
        $baseCapacity = 1 > $baseCapacity ? 0 : $baseCapacity;
        $fullCapacity = 1 > $fullCapacity ? 0 : $fullCapacity;
        $fullCapacity = max($fullCapacity, $baseCapacity);
        $this->setBaseCapacity($baseCapacity);
        $this->setFullCapacity($fullCapacity);
    }

    public function getCapacity(bool $full = false): ?int
    {
        return true === $full ? $this->getFullCapacity() : $this->getBaseCapacity();
    }

    public function getFullCapacity(): ?int
    {
        return $this->fullCapacity;
    }

    public function setFullCapacity(?int $fullCapacity): void
    {
        if (null === $fullCapacity) {
            $this->fullCapacity = null;

            return;
        }
        $this->fullCapacity = max(0, $fullCapacity);
    }

    /**
     * Základní kapacita, nejvýš však do plné.
     *
     * ⚠️ Chybí-li plná kapacita, platí základní beze změny. Dřív se tu porovnávalo
     * `getFullCapacity() < $this->baseCapacity` bez ošetření `null`: prázdná plná kapacita se
     * v porovnání chová jako NULA, takže podmínka vyšla vždy pravdivě a metoda vrátila `null` —
     * zadaná základní kapacita se tím tiše ztratila. Je to týž druh chyby jako u
     * {@see setCapacity()}, jen opačným směrem.
     */
    public function getBaseCapacity(): ?int
    {
        if (null === $this->baseCapacity) {
            return null;
        }
        $plna = $this->getFullCapacity();

        return null === $plna ? $this->baseCapacity : min($plna, $this->baseCapacity);
    }

    public function setBaseCapacity(?int $baseCapacity): void
    {
        if (null === $baseCapacity) {
            $this->baseCapacity = null;
            $this->fullCapacity = null;

            return;
        }
        $this->baseCapacity = max(0, $baseCapacity);
    }
}
