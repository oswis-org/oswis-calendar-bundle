<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Meal;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Meal\ParticipantMealChoiceRepository;
use OswisOrg\OswisCalendarBundle\State\MealChoiceProcessor;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Co si účastník vybral k jídlu.
 *
 * Vize B7: volbu si účastník nakliká sám v aplikaci, NEBO mu ji zadá tým u evidence (check-in) —
 * jednotící princip „self-service + týmové zadání pro lidi bez mobilu".
 *
 * **Uzávěrka je PŘI PŘÍJEZDU**, ne den předem: po odbavení na evidenci je volba zamčená a mění ji
 * jen tým. Hlídá to {@see MealChoiceProcessor}.
 *
 * ⚠️ **Bez `DeletedTrait` schválně.** Unikátní klíč `(participant, meal)` drží pravidlo „jedno
 * jídlo = jedna volba"; se soft-delete by smazaná volba klíč blokovala a účastník by si pak
 * nemohl vybrat znovu. Volba není provozní záznam, který by se musel dohledávat zpětně —
 * je to aktuální stav. Mazání je proto tvrdé.
 *
 * ⚠️ **Příjezdová večeře se předem nevybírá** (vize). Nepotřebuje to zvláštní kód: jídlo bez
 * variant je čistě informativní, takže tým prostě u té večeře varianty nezadá a nebude z čeho
 * vybírat. Kdyby se to řešilo výjimkou v kódu, byla by to pravidla na dvou místech.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['calendar_meal_choices_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['calendar_meal_choice_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        // ⚠️ `processor` řeší DVĚ věci: resolvování IRI relací (jinak 500 „A new entity was found")
        // a ZÁMEK po check-inu. Bez něj by si účastník mohl volbu přepsat i po příjezdu, kdy už
        // kuchyň vaří podle odevzdaných počtů.
        new Post(
            denormalizationContext: ['groups' => ['calendar_meal_choices_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
            processor: MealChoiceProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['calendar_meal_choice_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['calendar_meal_choices_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
            processor: MealChoiceProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_CUSTOMER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['participant' => 'exact', 'meal' => 'exact'])]
#[Entity(repositoryClass: ParticipantMealChoiceRepository::class)]
#[Table(name: 'calendar_participant_meal_choice')]
// Jeden účastník + jedno jídlo = jedna volba. Bez toho by dvojí odeslání formuláře udělalo
// dvě volby k témuž obědu a kuchyňský součet by seděl o jednu porci vedle.
// ⚠️ Constraint MUSÍ být i v migraci — a naopak; jinak `doctrine:schema:validate` zrezaví.
#[UniqueConstraint(name: 'meal_choice_participant_meal_uniq', columns: ['participant_id', 'meal_id'])]
#[Index(name: 'meal_choice_meal_idx', columns: ['meal_id'])]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_meal')]
class ParticipantMealChoice implements BasicInterface
{
    use BasicTrait;

    /** Čí volba. Setterem, ne konstruktorem — kvůli resolvování IRI. */
    #[ManyToOne(targetEntity: Participant::class)]
    #[JoinColumn(name: 'participant_id', nullable: false)]
    #[Assert\NotNull]
    protected ?Participant $participant = null;

    /**
     * Ke kterému jídlu se volba vztahuje.
     *
     * Drží se ZVLÁŠŤ od varianty, i když by šlo odvodit z `variant->meal`: jen tak jde unikátní
     * klíč `(participant, meal)` vynutit v databázi. Odvozený sloupec by pravidlo „jedno jídlo =
     * jedna volba" nechal na aplikaci, kde se dá obejít souběhem dvou requestů.
     */
    // ⚠️ ZDE ZÁMĚRNĚ BEZ `Assert\NotNull`, i když sloupec je NOT NULL: validace API Platform
    // běží PŘED procesorem, takže v ní jídlo ještě dosazené není (dopočítá ho `setVariant()`
    // z varianty, a ta se resolvuje až v procesoru). S `NotNull` by každý zápis skončil 422
    // dřív, než by se k němu procesor vůbec dostal. Přítomnost hlídá `MealChoiceProcessor`.
    #[ManyToOne(targetEntity: Meal::class)]
    #[JoinColumn(name: 'meal_id', nullable: false)]
    protected ?Meal $meal = null;

    /** Vybraná varianta. */
    #[ManyToOne(targetEntity: MealVariant::class)]
    #[JoinColumn(name: 'variant_id', nullable: false)]
    #[Assert\NotNull]
    protected ?MealVariant $variant = null;

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): void
    {
        $this->participant = $participant;
    }

    public function getMeal(): ?Meal
    {
        return $this->meal;
    }

    public function setMeal(?Meal $meal): void
    {
        $this->meal = $meal;
    }

    public function getVariant(): ?MealVariant
    {
        return $this->variant;
    }

    /**
     * Nastaví variantu a DOPLNÍ z ní jídlo, pokud ještě není.
     *
     * Klient posílá jen variantu (to je to, na co ťukl); jídlo je odvoditelné a nemá smysl po něm
     * chtít, aby ho posílal taky — zbytečná příležitost poslat nesouhlasnou dvojici.
     */
    public function setVariant(?MealVariant $variant): void
    {
        $this->variant = $variant;
        if (null !== $variant && null === $this->meal) {
            $this->meal = $variant->getMeal();
        }
    }

    /** Sedí varianta k jídlu? Nesouhlasná dvojice by rozbila kuchyňský součet. */
    public function jeKonzistentni(): bool
    {
        return null !== $this->variant && null !== $this->meal
            && $this->variant->getMeal()?->getId() === $this->meal->getId();
    }
}
