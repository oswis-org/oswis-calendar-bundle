<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use OswisOrg\OswisCalendarBundle\Entity\CheckIn\CheckInStation;
use OswisOrg\OswisCalendarBundle\Entity\Meal\ParticipantMealChoice;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Zápis volby jídla — vlastnictví, konzistence a uzávěrka při příjezdu.
 *
 * Resolvování IRI relací NEŘEŠÍ sám: deleguje na {@see ProgramApiProcessor}, aby ta past
 * („A new entity was found through the relationship") měla v celém projektu jedno místo.
 * Tenhle procesor přidává tři pravidla, která u volby jídla platí navíc:
 *
 *  1. **Účastník smí zapisovat jen SVOU volbu.** Bez toho by stačilo poslat cizí IRI přihlášky
 *     a přepsat oběd někomu jinému — čtecí extension chrání jen čtení, ne zápis.
 *  2. **Varianta musí patřit k jídlu.** Nesouhlasná dvojice by rozbila kuchyňský součet
 *     (porce by se započítala k jinému jídlu, než se vydává).
 *  3. **Po odbavení na evidenci je zamčeno** (vize B7: „deadline výběru = PŘI PŘÍJEZDU, ne
 *     předchozí den"). Od té chvíle mění volbu jen tým — kuchyň už vaří podle odevzdaných počtů.
 *
 * Tým (ROLE_MANAGER+) obchází všechna tři: zadává volby lidem bez mobilu u evidence a opravuje
 * je i po příjezdu.
 *
 * @implements ProcessorInterface<object, object>
 */
final class MealChoiceProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<object, object> $persistProcessor
     */
    public function __construct(
        private readonly ProgramApiProcessor $relations,
        private readonly ProcessorInterface $persistProcessor,
        private readonly Security $security,
        private readonly ParticipantService $participantService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // ⚠️ Pořadí je podstatné: dosadit relace → ZKONTROLOVAT → teprve uložit. Kdyby se
        // kontrolovalo až po uložení, zamítnutý zápis by v databázi zůstal a výjimka by přišla
        // pozdě. Proto se volá `resolveRelations()`, ne celý `process()` vnitřního procesoru.
        $this->relations->resolveRelations($data);

        if ($data instanceof ParticipantMealChoice) {
            // Platí pro VŠECHNY včetně týmu: `meal` nemá `Assert\NotNull` (validace běží dřív než
            // tenhle procesor), takže bez téhle kontroly by chybějící jídlo skončilo databázovou
            // chybou 500 místo srozumitelné hlášky. Konzistence dvojice patří sem ze stejného
            // důvodu — špatná dvojice rozbije kuchyňský součet, ať ji pošle kdokoli.
            if (!$data->jeKonzistentni()) {
                throw new BadRequestHttpException('Vybraná varianta nepatří k tomuto jídlu.');
            }
        }

        if ($data instanceof ParticipantMealChoice && !$this->jeTym()) {
            $participant = $data->getParticipant();
            if (!$participant instanceof Participant || !$this->jeMoje($participant)) {
                throw new AccessDeniedHttpException('Měnit lze jen vlastní volbu jídla.');
            }
            if ($this->jePoPrijezdu($participant)) {
                throw new AccessDeniedHttpException(
                    'Výběr jídla je po příjezdu uzavřený. Změnu domluv s týmem na místě.',
                );
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function jeTym(): bool
    {
        return $this->security->isGranted('ROLE_MANAGER')
            || $this->security->isGranted('ROLE_ADMIN')
            || $this->security->isGranted('ROLE_ROOT');
    }

    private function jeMoje(Participant $participant): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof AppUser) {
            return false;
        }
        foreach ($this->participantService->getParticipants(
            [ParticipantRepository::CRITERIA_APP_USER => $user],
        ) as $moje) {
            if ($moje->getId() === $participant->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Odbavil se účastník na evidenci?
     *
     * Příjezd se pozná podle DOKONČENÉ návštěvy stanoviště typu `evidence` — to je prezence
     * u příjezdu. Ostatní stanoviště (pásky, ubytování, jídlo…) se odbavují až po ní a nejsou
     * pro uzávěrku rozhodující.
     */
    private function jePoPrijezdu(Participant $participant): bool
    {
        foreach ($participant->getStationVisits() as $navsteva) {
            if (null !== $navsteva->getCompletedAt()
                && CheckInStation::KIND_EVIDENCE === $navsteva->getStation()?->getStationKind()) {
                return true;
            }
        }

        return false;
    }
}
