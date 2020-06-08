<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagCategoryRange;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagRange;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagRangeCategoryRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipantFlagType extends AbstractType
{
    final public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $participant = $options['participant'] ?? null;
        $this->addFlagField($builder, $participant);
    }

    public function addFlagField(FormBuilderInterface $builder, ?Participant $participant): void {
        // TODO: Implement it. Can't be flags added directly to participant???
        $participantFlagCategory = $builder->getData();
        assert($participantFlagCategory instanceof ParticipantFlagCategory);
        $flagCategoryRange = $participantFlagCategory->getFlagCategoryRange();
        $flagCategory = $participantFlagCategory->getFlagCategory();
        if (null === $flagCategoryRange || null === $flagCategory) {
            return;
        }
        $isFormal = $participant ? $participant->isFormal() : true;
        $min = $flagCategoryRange->getMin();
        $max = $flagCategoryRange->getMax();
        if (!$flagCategoryRange->isFormValueAllowed()) {
            // TODO: Implement it.
            throw new NotImplementedException();
        }
        $this->addSimpleSubForm($builder, $participantFlagCategory, $min, $max, $isFormal);
    }

    public function addSimpleSubForm(
        FormBuilderInterface $builder,
        ParticipantFlagCategory $participantFlagCategory,
        int $min,
        ?int $max,
        bool $isFormal = true
    ): void {
        $flagCategoryRange = $participantFlagCategory->getFlagCategoryRange();
        if (null === $flagCategoryRange) {
            return;
        }
        $choices = $flagCategoryRange->getFlagRanges();
        $youCan = $isFormal ? 'můžete' : 'můžeš';
        $required = !empty($min);
        $multiple = null !== $max && $max > 1;
        $expanded = count($choices) < 2;
        $label = $flagCategoryRange ? $flagCategoryRange->getName() : 'Ostatní příznaky';
        $help = $flagCategoryRange ? $flagCategoryRange->getDescription() : '<p>Ostatní příznaky, které nespadají do žádné kategorie.</p>';
        $help .= !$expanded && $multiple ? "<p>Pro výběr více položek nebo zrušení $youCan použít klávesu <span class='keyboard-key'>CTRL</span>.</p>" : '';
        $builder->add(
            "participantFlags",
            ChoiceType::class,
            [
                'label'        => $label,
                'help_html'    => true,
                'help'         => $help,
                'required'     => $required,
                'choices'      => $choices,
                'mapped'       => true, // false,
                'expanded'     => $expanded,
                'multiple'     => $multiple,
                'attr'         => [
                    'size' => $multiple ? count($choices) + count(self::getFlagsGroupNames($flagCategoryRange)) : null,
                ],
                'choice_label' => fn(RegistrationFlagRange $flagRange, $key, $value) => self::getFlagName($flagRange),
                'choice_attr'  => fn(RegistrationFlagRange $flagRange, $key, $value) => self::getChoiceAttributes($flagRange),
                'group_by'     => fn(RegistrationFlagRange $flagRange, $key, $value) => self::getFlagGroupName($flagRange),
                'placeholder'  => $flagCategoryRange->getEmptyPlaceholder(),
            ]
        );
    }

    public static function getFlagsGroupNames(RegistrationFlagCategoryRange $flagCategoryRange): array
    {
        $groups = [];
        foreach ($flagCategoryRange->getFlagRanges(true) as $flagRange) {
            $groupName = self::getFlagGroupName($flagRange);
            $groups[$groupName] = ($groups[$groupName] ?? 0) + 1;
        }

        return $groups;
    }

    public static function getFlagGroupName(RegistrationFlagRange $flagRange): ?string
    {
        if (null === $flagRange) {
            return null;
        }
        if (RegistrationFlagRangeCategory::TYPE_T_SHIRT === $flagRange->getType()) {
            return self::getTShirtGroupName($flagRange);
        }

        return self::getFlagPriceGroup($flagRange);
    }

    public static function getTShirtGroupName(RegistrationFlagRange $flagRange): string
    {
        $flagName = $flagRange->getFlag() ? $flagRange->getFlag()->getName() : null;
        if (strpos($flagName, 'Pán') !== false) {
            return '♂ Pánské tričko';
        }
        if (strpos($flagName, 'Dám') !== false) {
            return '♀ Dámské tričko';
        }
        if (strpos($flagName, 'Uni') !== false) {
            return '⚲ Unisex tričko';
        }

        return '⚪ Ostatní';
    }

    public static function getFlagPriceGroup(RegistrationFlagRange $flagRange): string
    {
        $price = $flagRange->getPrice();
        if ($price > 0) {
            return '⊕ S příplatkem';
        }
        if ($price < 0) {
            return '⊖ Se slevou';
        }

        return '⊜ Bez příplatku';
    }

    public static function getFlagName(RegistrationFlagRange $flagRange, bool $withPrice = true): string
    {
        $flagName = $flagRange->getName();
        if ($withPrice) {
            $price = $flagRange->getPrice();
            $flagName .= 0 !== $price ? ' ['.($price > 0 ? '+' : '').$price.',- Kč]' : '';
        }
        if (!$flagRange->hasRemainingCapacity()) {
            $flagName .= ' (kapacita vyčerpána)';
        }

        return $flagName;
    }

    public static function getChoiceAttributes(RegistrationFlagRange $flagRange): array
    {
        $attributes = [];
        if ($flagRange->hasRemainingCapacity()) {
            $attributes['disabled'] = 'disabled';
        }

        return $attributes;
    }





    /**
     * @param OptionsResolver $resolver
     *
     * @throws AccessException
     */
    final public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            array(
                'data_class' => ParticipantFlagCategory::class,
//                'attr' => ['class' => 'col-md-6'],
            )
        );
    }

    final public function getName(): string
    {
        return 'calendar_participant_note';
    }
}
