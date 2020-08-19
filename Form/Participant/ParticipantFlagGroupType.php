<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagRange;
use OswisOrg\OswisCalendarBundle\Repository\FlagRangeRepository;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Exception\AlreadySubmittedException;
use Symfony\Component\Form\Exception\LogicException;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
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
    protected FlagRangeRepository $flagRangeRepository;

    protected LoggerInterface $logger;

    public function __construct(FlagRangeRepository $flagRangeRepository, LoggerInterface $logger)
    {
        $this->flagRangeRepository = $flagRangeRepository;
        $this->logger = $logger;
    }

    /**
     * @param FormInterface        $form
     * @param ParticipantFlagGroup $participantFlagCategory
     *
     * @throws AlreadySubmittedException
     * @throws LogicException
     * @throws UnexpectedTypeException
     */
    public static function addCheckboxes(FormInterface $form, ParticipantFlagGroup $participantFlagCategory): void
    {
        $flagGroupRange = $participantFlagCategory->getFlagGroupRange();
        if (null === $flagGroupRange) {
            return;
        }
        $form->add(
            "participantFlags",
            CollectionType::class,
            [
                'entry_type' => ParticipantFlagType::class,
                'label'      => $flagGroupRange->getName() ?? 'Ostatní příznaky',
                'help'       => $flagGroupRange->getDescription() ?? '<p>Ostatní příznaky, které nespadají do žádné kategorie.</p>',
                'help_html'  => true,
            ]
        );
    }

    /**
     * @param FormInterface        $form
     * @param ParticipantFlagGroup $participantFlagGroup
     * @param int                  $min
     * @param int|null             $max
     * @param bool                 $isFormal
     *
     * @throws AlreadySubmittedException
     * @throws LogicException
     * @throws UnexpectedTypeException
     */
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
            $help .= "<p>Pro výběr více položek nebo zrušení výběru $youCan použít klávesu <span class='keyboard-key'>CTRL</span>.</p>";
        }
        $form->add(
            "tempFlagRanges",
            EntityType::class,
            [
                'class'              => FlagRange::class,
                'label'              => false,
                'required'           => false,
                'help_html'          => true,
                'choices'            => $flagGroupRange->getFlagRanges(),
                // 'empty_data'         => null,
                'multiple'           => true,
                'attr'               => ['style' => 'display:none'],
                'choice_label'       => fn(FlagRange $flagRange, $key, $value) => $flagRange->getExtendedName(),
                'allow_extra_fields' => true,
            ]
        );
        $form->add(
            "flagRanges",
            EntityType::class,
            [
                'class'        => FlagRange::class,
                'mapped'       => false,
                'label'        => $flagGroupRange->getFlagGroupName() ?? 'Ostatní příznaky',
                'help_html'    => true,
                'help'         => $help,
                'required'     => !empty($min),
                'choices'      => $choices,
                'expanded'     => $expanded,
                // 'empty_data'   => new ArrayCollection(),
                'multiple'     => $multiple,
                'attr'         => ['size' => $multiple ? (count($choices) + count($flagGroupRange->getFlagsGroupNames())) : null],
                'choice_label' => fn(FlagRange $flagRange, $key, $value) => $flagRange->getExtendedName(),
                'choice_attr'  => fn(FlagRange $flagRange, $key, $value) => self::getChoiceAttributes($flagRange),
                'group_by'     => fn(FlagRange $flagRange, $key, $value) => $flagRange->getFlagGroupName(),
                'placeholder'  => $flagGroupRange->getEmptyPlaceholder(),
            ]
        );
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

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'onPreSetData']);
        // $builder->addEventListener(FormEvents::PRE_SUBMIT, [$this, 'onSubmit']);
        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            static function (FormEvent $event) {
                $data = $event->getData();
                if (!empty($data)) {
                    $data['tempFlagRanges'] = is_string($data['flagRanges'] ?? []) ? [$data['flagRanges']] : $data['flagRanges'];
                }
                $event->setData($data);
            }
        );
        $builder->addEventListener(
            FormEvents::SUBMIT,
            static function (FormEvent $event) {
                $participantFlagGroup = $event->getData();
                assert($participantFlagGroup instanceof ParticipantFlagGroup);
                $participantFlags = new ArrayCollection();
                foreach ($participantFlagGroup->tempFlagRanges as $tempFlagRange) {
                    assert($tempFlagRange instanceof FlagRange);
                    $participantFlag = new ParticipantFlag($tempFlagRange, $participantFlagGroup);
                    $participantFlags->add($participantFlag);
                }
                $participantFlagGroup->setParticipantFlags($participantFlags);
            }
        );
    }

    /**
     * @param FormEvent $event
     *
     * @throws AlreadySubmittedException
     * @throws LogicException
     * @throws OswisException
     * @throws UnexpectedTypeException
     */
    public function onPreSetData(FormEvent $event): void
    {
        $participantFlagGroup = $event->getData();
        assert($participantFlagGroup instanceof ParticipantFlagGroup);
        $participant = $event->getForm()->getConfig()->getOptions()['participant'];
        if (!($participant instanceof Participant)) {
            throw new OswisException('Ve formuláři chybí instance účastníka...');
        }
        $flagGroupRange = $participantFlagGroup->getFlagGroupRange();
        $flagCategory = $participantFlagGroup->getFlagCategory();
        if (null === $flagGroupRange || null === $flagCategory) {
            return;
        }
        $isFormal = false !== $participant->isFormal();
        $min = $flagGroupRange->getMin();
        $max = $flagGroupRange->getMax();
        if ($flagGroupRange->isFlagValueAllowed()) {
            self::addCheckboxes($event->getForm(), $participantFlagGroup);

            return;
        }
        self::addSelect($event->getForm(), $participantFlagGroup, $min, $max, $isFormal);
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
                'empty_data'  => new ArrayCollection(),
                // 'attr' => ['class' => 'col-md-6'],
            ]
        );
    }

    public function getName(): string
    {
        return 'calendar_participant_flag_group';
    }
}
