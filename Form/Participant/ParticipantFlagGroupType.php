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
use Symfony\Component\Form\Exception\OutOfBoundsException;
use Symfony\Component\Form\Exception\RuntimeException;
use Symfony\Component\Form\Exception\TransformationFailedException;
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

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'onPreSetData']);
        // $builder->addEventListener(FormEvents::PRE_SUBMIT, [$this, 'onSubmit']);
        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event) {
                $data = $event->getData();
                $flagRanges = $data['flagRanges'] ?? [];
                // $data['flagRanges'] = is_string($data['flagRanges']) ? [$data['flagRanges']] : $data['flagRanges'];
                $data['tempFlagRanges'] = is_string($data['flagRanges']) ? [$data['flagRanges']] : $data['flagRanges'];
//                $data['tempFlagRanges'] ??= new ArrayCollection();
//                if (is_string($flagRanges)) {
//                    $flagRange = $this->flagRangeRepository->getFlagRange(['id' => (int)$flagRanges]);
//                    $data['tempFlagRanges']->add($flagRange);
//                }
//                if (is_array($flagRanges)) {
//                    foreach ($flagRanges as $flagRangeId) {
//                        $flagRange = $this->flagRangeRepository->getFlagRange(['id' => (int)$flagRangeId]);
//                        $data['tempFlagRanges']->add($flagRange);
//                    }
//                }
                $this->logger->info("Temporary flag ranges (PRE_SUBMIT):");
                foreach ($data['tempFlagRanges'] as $tempFlagRange) {
                    $this->logger->info($tempFlagRange);
                }
                $this->logger->info("----------------------");
                $event->setData($data);
            }
        );
        $builder->addEventListener(
            FormEvents::SUBMIT,
            function (FormEvent $event) {
                $participantFlagGroup = $event->getData();
                assert($participantFlagGroup instanceof ParticipantFlagGroup);
                $this->logger->info("Temporary flag ranges (SUBMIT):");
                $participantFlags = new ArrayCollection();
                foreach ($participantFlagGroup->tempFlagRanges as $tempFlagRange) {
                    assert($tempFlagRange instanceof FlagRange);
                    $this->logger->info("Adding flag range: ".$tempFlagRange->getName());
                    $participantFlag = new ParticipantFlag($tempFlagRange, $participantFlagGroup);
                    $participantFlags->add($participantFlag);
                    $this->logger->info("Added participant flag: ".$participantFlag->getFlagRange()->getName());
                }
                $this->logger->info('$participantFlagGroup->setParticipantFlags($participantFlags);');
                $participantFlagGroup->setParticipantFlags($participantFlags);
                $this->logger->info("----------------------");
                foreach ($event->getData()->getParticipantFlags() as $pFlag) {
                    $this->logger->info("Participant flag: ".$pFlag->getFlagRange()->getName());
                }
                $this->logger->info("############################");
//                $tempFlagRanges = $participantFlagGroup->tempFlagRanges ?? new ArrayCollection();
//                $participantFlags = new ArrayCollection();
//                foreach ($tempFlagRanges as $tempFlagRange) {
//                    // $participantFlags->add(new ParticipantFlag($tempFlagRange));
//                }
//                $this->logger->info("PARTICIPANT FLAGS (in SUBMIT event):");
//                foreach ($participantFlags as $item) {
//                    assert($item instanceof ParticipantFlag);
//                    $this->logger->info($item->getFlag()->getName());
//                }
//                $this->logger->info("::::::END:::::");
//                $participantFlagGroup->setParticipantFlags($participantFlags);
//                $event->setData($participantFlagGroup);
//                $this->logger->info("Count (in submit):");
//                $this->logger->info(count($event->getData()->getParticipantFlags()));
//                $this->logger->info("::::::END:::::");
            }
        );
    }

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
        $form->add(
            "tempFlagRanges",
            EntityType::class,
            [
                'class'              => FlagRange::class,
                'label'              => false,
                'required'           => false,
                'help_html'          => true,
                'choices'            => $flagGroupRange->getFlagRanges(),
                'empty_data'         => new ArrayCollection(),
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
                'empty_data'   => new ArrayCollection(),
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

    /**
     * @param FormEvent $event
     *
     * @throws AlreadySubmittedException
     * @throws LogicException
     * @throws OutOfBoundsException
     * @throws RuntimeException
     * @throws TransformationFailedException
     */
    public function onSubmit(FormEvent $event): void
    {
//        $formData = $event->getForm()->getData();
//        $participantFlagsChild = $event->getForm()->get('participantFlags');
//        $flagRangesChild = $event->getForm()->get('flagRanges');
//        try {
//            $textValueChild = $event->getForm()->get('textValue');
//            $textValue = $textValueChild ? $textValueChild->getData() : null;
//        } catch (OutOfBoundsException $exception) {
//            $textValue = null;
//        }
//        error_log('Text value is: '. $textValue);
//        if (null === $flagRangesChild || null === $participantFlagsChild) {
//            error_log('Childs empty!!!');
//            return;
//        }
//        $flagRanges = $flagRangesChild->getData();
//        if ($flagRanges instanceof FlagRange) {
//            $flagRanges = new ArrayCollection([$flagRanges]);
//        }
//        $participantFlags = new ArrayCollection();
//        foreach ($flagRanges as $flagRange) {
//            assert($flagRange instanceof FlagRange);
//            error_log('Processing flag range: ' . $flagRange->getId());
//            $participantFlags->add(new ParticipantFlag($flagRange, $textValue));
//        }
//        $participantFlagsChild->setData($participantFlags);
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
