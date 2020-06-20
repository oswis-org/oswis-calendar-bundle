<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\Event\FlagRange;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagRangeCategoryRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Exception\AlreadySubmittedException;
use Symfony\Component\Form\Exception\LogicException;
use Symfony\Component\Form\Exception\OutOfBoundsException;
use Symfony\Component\Form\Exception\RuntimeException;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipantFlagGroupType extends AbstractType
{
    final public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $participant = $options['participant'] ?? null;
        $this->addCategoryField($builder, $participant);
    }

    public function addCategoryField(FormBuilderInterface $builder, ?Participant $participant): void
    {
        // TODO: Implement it. Can't be flags added directly to participant???
        $participantFlagCategory = $builder->getData();
        assert($participantFlagCategory instanceof ParticipantFlagGroup);
        $flagCategoryRange = $participantFlagCategory->getFlagGroupRange();
        $flagCategory = $participantFlagCategory->getFlagCategory();
        if (null === $flagCategoryRange || null === $flagCategory) {
            return;
        }
        $isFormal = $participant ? $participant->isFormal() : true;
        $min = $flagCategoryRange->getMin();
        $max = $flagCategoryRange->getMax();
        if (!$flagCategoryRange->isCategoryValueAllowed()) {
            $this->addCheckboxes($builder, $participantFlagCategory);

            return;
        }
        $this->addSelect($builder, $participantFlagCategory, $min, $max, $isFormal);
    }

    public function addCheckboxes(FormBuilderInterface $builder, ParticipantFlagGroup $participantFlagCategory): void
    {
        $flagCategoryRange = $participantFlagCategory->getFlagGroupRange();
        if (null === $flagCategoryRange) {
            return;
        }
        $builder->add(
            "participantFlags",
            CollectionType::class,
            [
                'entry_type' => ParticipantFlagType::class,
                'label'      => $flagCategoryRange->getName() ?? 'Ostatní příznaky',
                'help'       => $flagCategoryRange->getDescription() ?? '<p>Ostatní příznaky, které nespadají do žádné kategorie.</p>',
                'help_html'  => true,
            ]
        );
        $builder->addEventListener(FormEvents::PRE_SUBMIT, [$this, 'onPreSubmit']);
    }

    public function addSelect(
        FormBuilderInterface $builder,
        ParticipantFlagGroup $participantFlagCategory,
        int $min,
        ?int $max,
        bool $isFormal = true
    ): void {
        $flagCategoryRange = $participantFlagCategory->getFlagGroupRange();
        if (null === $flagCategoryRange) {
            return;
        }
        $choices = $flagCategoryRange->getFlagRanges();
        $youCan = $isFormal ? 'můžete' : 'můžeš';
        $multiple = null !== $max && $max > 1;
        $expanded = count($choices) <= 1;
        $help = $flagCategoryRange ? $flagCategoryRange->getDescription() : '<p>Ostatní příznaky, které nespadají do žádné kategorie.</p>';
        $help .= !$expanded && $multiple ? "<p>Pro výběr více položek nebo zrušení $youCan použít klávesu <span class='keyboard-key'>CTRL</span>.</p>" : '';
        $builder->add(
            "participantFlags",
            ChoiceType::class,
            [
                'label'        => $flagCategoryRange->getName() ?? 'Ostatní příznaky',
                'help_html'    => true,
                'help'         => $help,
                'required'     => !empty($min),
                'choices'      => $choices,
                'expanded'     => $expanded,
                'multiple'     => $multiple,
                'attr'         => ['size' => $multiple ? (count($choices) + count($flagCategoryRange->getFlagsGroupNames())) : null,],
                'choice_label' => fn(FlagRange $flagRange, $key, $value) => $flagRange->getExtendedName(),
                'choice_attr'  => fn(FlagRange $flagRange, $key, $value) => self::getChoiceAttributes($flagRange),
                'group_by'     => fn(FlagRange $flagRange, $key, $value) => $flagRange->getFlagGroupName(),
                'placeholder'  => $flagCategoryRange->getEmptyPlaceholder(),
            ]
        );
        if ($flagCategoryRange->isCategoryValueAllowed()) {
            $builder->add(
                "textValue",
                TextType::class,
                [
                    'label'     => $flagCategoryRange->getFormValueLabel(),
                    'help_html' => true,
                ]
            );
        }
    }

    public static function getChoiceAttributes(FlagRange $flagRange): array
    {
        $attributes = [];
        if ($flagRange->hasRemainingCapacity()) {
            $attributes['disabled'] = 'disabled';
        }

        return $attributes;
    }

    /**
     * @param FormEvent $event
     *
     * @throws AlreadySubmittedException
     * @throws LogicException
     * @throws OutOfBoundsException
     * @throws RuntimeException
     * @throws TransformationFailedException
     */
    public function onPreSubmit(FormEvent $event): void
    {
        $participantFlagsChild = $event->getForm()->get('participantFlags');
        $participantFlags = $participantFlagsChild->getData();
        assert($participantFlags instanceof Collection);
        foreach ($participantFlags as $participantFlag) {
            assert($participantFlag instanceof ParticipantFlag);
            if (null === $participantFlag->getFlagRange()) {
                $participantFlags->remove($participantFlag);
            }
        }
        $participantFlagsChild->setData($participantFlags);
    }

    /**
     * @param OptionsResolver $resolver
     *
     * @throws AccessException
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'data_class'  => ParticipantFlagGroup::class,
                'participant' => null,
                // 'attr' => ['class' => 'col-md-6'],
            ]
        );
    }

    public function getName(): string
    {
        return 'calendar_participant_category';
    }
}
