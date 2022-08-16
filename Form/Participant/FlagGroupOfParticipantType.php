<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationFlagOfferRepository;
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

class FlagGroupOfParticipantType extends AbstractType
{
    protected RegistrationFlagOfferRepository $flagRangeRepository;

    protected LoggerInterface $logger;

    public function __construct(RegistrationFlagOfferRepository $flagRangeRepository, LoggerInterface $logger)
    {
        $this->flagRangeRepository = $flagRangeRepository;
        $this->logger = $logger;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'onPreSetData']);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function(FormEvent $event) {
            $data = $event->getData();
            if (!empty($data) && is_array($data)) {
                $data['tempFlagRanges'] = is_string($data['flagRanges'] ?? []) ? [$data['flagRanges']]
                    : $data['flagRanges'];
            }
            $event->setData($data);
        });
        $builder->addEventListener(FormEvents::SUBMIT, static function(FormEvent $event) {
            $participantFlagGroup = $event->getData();
            assert($participantFlagGroup instanceof ParticipantFlagGroup);
            $participantFlags = new ArrayCollection();
            foreach ($participantFlagGroup->tempFlagRanges ?? new ArrayCollection() as $tempFlagRange) {
                assert($tempFlagRange instanceof RegistrationFlagOffer);
                $participantFlag = new ParticipantFlag($tempFlagRange, $participantFlagGroup);
                $participantFlag->activate();
                $participantFlags->add($participantFlag);
            }
            $participantFlagGroup->setParticipantFlags($participantFlags);
        });
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
        $flagGroupRange = $participantFlagGroup->getFlagGroupOffer();
        $flagCategory = $participantFlagGroup->getFlagCategory();
        if (null === $flagGroupRange || null === $flagCategory || empty($flagGroupRange->isPublicOnWeb())) {
            return;
        }
        $isFormal = false !== $participant->isFormal();
        $min = $flagGroupRange->getMin();
        $max = $flagGroupRange->getMax();
        if ($flagGroupRange->isFlagValueAllowed()) {
            self::addCheckboxes($event->getForm(), $participantFlagGroup, $min);

            return;
        }
        self::addSelect($event->getForm(), $participantFlagGroup, $min, $max, $isFormal);
    }

    /**
     * @param FormInterface        $form
     * @param ParticipantFlagGroup $participantFlagCategory
     * @param int|null             $min
     *
     * @throws \Symfony\Component\Form\Exception\AlreadySubmittedException
     * @throws \Symfony\Component\Form\Exception\LogicException
     * @throws \Symfony\Component\Form\Exception\UnexpectedTypeException
     */
    public static function addCheckboxes(
        FormInterface $form,
        ParticipantFlagGroup $participantFlagCategory,
        int $min = null,
    ): void {
        $flagGroupRange = $participantFlagCategory->getFlagGroupOffer();
        if (null === $flagGroupRange) {
            return;
        }
        $helpText = $flagGroupRange->getDescription();
        $form->add("participantFlags", CollectionType::class, [
            'entry_type' => FlagOfParticipantType::class,
            'required' => $min !== null && $min > 0,
            'label' => $flagGroupRange->getName() ?? 'Ostatní příznaky',
            'help' => empty($helpText) ? '<p>Ostatní příznaky, které nespadají do žádné kategorie.</p>' : $helpText,
            'help_html' => true,
        ]);
    }

    public function getName(): string
    {
        return 'calendar_participant_flag_group';
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
    public static function addSelect(
        FormInterface $form,
        ParticipantFlagGroup $participantFlagGroup,
        int $min,
        ?int $max,
        bool $isFormal = true
    ): void {
        if (null === ($flagGroupRange = $participantFlagGroup->getFlagGroupOffer())) {
            return;
        }
        $flagCategory = $flagGroupRange->getFlagCategory();
        $choices = $flagGroupRange->getFlagOffers();
        $multiple = null === $max || $max > 1;
        $expanded = count($choices) <= 1;
        $help = $flagGroupRange->getDescription();
        $flagCategoryName = $flagCategory ? $flagCategory->getDescription() : '';
        $help = empty($help) ? $flagCategoryName : $help;
        if (!$expanded && $multiple) {
            $youCan = $isFormal ? 'můžete' : 'můžeš';
            $help .= "<p>Pro výběr více položek nebo zrušení výběru $youCan použít klávesu <span class='keyboard-key'>CTRL</span>.</p>";
        }
        $form->add("tempFlagRanges", EntityType::class, [
            'class' => RegistrationFlagOffer::class,
            'label' => false,
            'required' => false,
            'help_html' => true,
            'choices' => $flagGroupRange->getFlagOffers(),
            // 'empty_data'         => null,
            'multiple' => true,
            'attr' => ['style' => 'display:none'],
            'choice_label' => fn(RegistrationFlagOffer $flagRange, $key, $value) => $flagRange->getExtendedName(),
            'allow_extra_fields' => true,
        ]);
        $form->add("flagRanges", EntityType::class, [
            'class' => RegistrationFlagOffer::class,
            'mapped' => false,
            'label' => $flagGroupRange->getFlagGroupName() ?? 'Ostatní příznaky',
            'help_html' => true,
            'help' => $help,
            'required' => !empty($min) || $min > 0,
            'choices' => $choices,
            'expanded' => $expanded,
            // 'empty_data'   => new ArrayCollection(),
            'multiple' => $multiple,
            'attr' => [
                'size' => $multiple ? (count($choices) + count($flagGroupRange->getFlagsGroupNames())) : null,
            ],
            'choice_label' => fn(RegistrationFlagOffer $flagRange, $key, $value) => $flagRange->getExtendedName(),
            'choice_attr' => fn(
                RegistrationFlagOffer $flagRange,
                $key,
                $value
            ) => self::getChoiceAttributes($flagRange),
            'group_by' => fn(RegistrationFlagOffer $flagRange, $key, $value) => $flagRange->getFlagGroupName(),
            'placeholder' => $flagGroupRange->getEmptyPlaceholder(),
        ]);
        if ($flagGroupRange->isCategoryValueAllowed()) {
            $form->add("textValue", TextType::class, [
                'label' => $flagGroupRange->getFormValueLabel(),
                'help_html' => true,
            ]);
        }
    }

    public static function getChoiceAttributes(RegistrationFlagOffer $flagRange): array
    {
        $attributes = [];
        if (!$flagRange->hasRemainingCapacity()) {
            $attributes['disabled'] = 'disabled';
        }
        if ($flagRange->getMin() > 0) {
            $attributes['required'] = 'required';
        }

        return $attributes;
    }

    /**
     * @param OptionsResolver $resolver
     *
     * @throws AccessException
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ParticipantFlagGroup::class,
            'participant' => null,
            'empty_data' => new ArrayCollection(),
            // 'attr' => ['class' => 'col-md-6'],
        ]);
    }
}
