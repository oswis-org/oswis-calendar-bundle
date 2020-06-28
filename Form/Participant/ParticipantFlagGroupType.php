<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagRange;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipantFlagGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'onPreSetData']);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, [$this, 'onPreSubmit']);
    }

    public function onPreSetData(FormEvent $event): void
    {
        $participantFlagGroup = $event->getData();
        assert($participantFlagGroup instanceof ParticipantFlagGroup);
        $participant = $event->getForm()->getConfig()->getOptions()['participant'];
        if (!($participant instanceof Participant)) {
            throw new OswisException('Neexistuje Participant...!!!!!!!');
        }
        $flagGroupRange = $participantFlagGroup->getFlagGroupRange();
        $flagCategory = $participantFlagGroup->getFlagCategory();
        if (null === $flagGroupRange || null === $flagCategory) {
            return;
        }
        $isFormal = $participant && false === $participant->isFormal() ? false : true;
        $min = $flagGroupRange->getMin();
        $max = $flagGroupRange->getMax();
        if ($flagGroupRange->isFlagValueAllowed()) {
            self::addCheckboxes($event->getForm(), $participantFlagGroup);

            return;
        }
        self::addSelect($event->getForm(), $participantFlagGroup, $min, $max, $isFormal);
    }

    public static function addCheckboxes(FormInterface $form, ParticipantFlagGroup $participantFlagCategory): void
    {
        $flagCategoryRange = $participantFlagCategory->getFlagGroupRange();
        if (null === $flagCategoryRange) {
            return;
        }
        $form->add(
            "participantFlags",
            CollectionType::class,
            [
                'entry_type' => ParticipantFlagType::class,
                'label'      => $flagCategoryRange->getName() ?? 'Ostatní příznaky',
                'help'       => $flagCategoryRange->getDescription() ?? '<p>Ostatní příznaky, které nespadají do žádné kategorie.</p>',
                'help_html'  => true,
            ]
        );
    }

    public static function addSelect(FormInterface $form, ParticipantFlagGroup $participantFlagGroup, int $min, ?int $max, bool $isFormal = true): void
    {
        if (null === $flagGroupRange = $participantFlagGroup->getFlagGroupRange()) {
            return;
        }
        $flagCategory = $flagGroupRange->getFlagCategory();
        $choices = $flagGroupRange->getFlagRanges();
        $multiple = null === $max || $max > 1;
        $expanded = count($choices) <= 1;
        $help = $flagGroupRange->getDescription();
        $flagCategoryName = $flagCategory ? $flagCategory->getDescription() : '';
        $help = empty($help) ? $flagCategoryName : $help;
        if (!$expanded && $multiple) {
            $youCan = $isFormal ? 'můžete' : 'můžeš';
            $help .= "<p>Pro výběr více položek nebo zrušení $youCan použít klávesu <span class='keyboard-key'>CTRL</span>.</p>";
        }
        $options = [
            'label'        => $flagGroupRange->getFlagGroupName() ?? 'Ostatní příznaky',
            'help_html'    => true,
            'help'         => $help,
            'required'     => !empty($min),
            'choices'      => $choices,
            'expanded'     => $expanded,
            'multiple'     => $multiple,
            'attr'         => ['size' => $multiple ? (count($choices) + count($flagGroupRange->getFlagsGroupNames())) : null],
            'choice_label' => fn(FlagRange $flagRange, $key, $value) => $flagRange->getExtendedName(),
            'choice_attr'  => fn(FlagRange $flagRange, $key, $value) => self::getChoiceAttributes($flagRange),
            'group_by'     => fn(FlagRange $flagRange, $key, $value) => $flagRange->getFlagGroupName(),
            'placeholder'  => $flagGroupRange->getEmptyPlaceholder(),
        ];
        if ($multiple) {
            $options['class'] = FlagRange::class;
        }
        $form->add("participantFlags", $multiple ? EntityType::class : ChoiceType::class, $options);
        if ($flagGroupRange->isCategoryValueAllowed()) {
            $form->add(
                "textValue",
                TextType::class,
                [
                    'label'     => $flagGroupRange->getFormValueLabel(),
                    'help_html' => true,
                ]
            );
        }
    }

    public static function getChoiceAttributes(FlagRange $flagRange): array
    {
        $attributes = [];
        if (!$flagRange->hasRemainingCapacity()) {
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
        return 'calendar_participant_flag_group';
    }
}
