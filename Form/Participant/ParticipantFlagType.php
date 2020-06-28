<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagRangeCategoryRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipantFlagType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'onPreSetData']);
    }

    public function onPreSetData(FormEvent $event): void
    {
        if (null === ($participantFlag = $event->getData()) || null === ($flagRange = $participantFlag->getFlagRange())) {
            return;
        }
        $event->getForm()->add(
            "flagRange",
            CheckboxType::class,
            [
                'label_html' => true,
                'label'      => $flagRange->getExtendedName(),
                'required'   => $flagRange->getMin() > 0,
                'value'      => $flagRange,
                'disabled'   => !$flagRange->hasRemainingCapacity(),
                'help_html'  => true,
                'help'       => $flagRange->getDescription(),
            ]
        );
        if ($participantFlag->getFlagRange() && $participantFlag->getFlagRange()->isFormValueAllowed()) {
            $event->getForm()->add(
                "textValue",
                TextType::class,
                [
                    'label_html' => true,
                    'label'      => $flagRange->getFormValueLabel(),
                    'attr'       => ['pattern' => $flagRange->getFormValueRegex()],
                    'required'   => $flagRange->getMin() > 0,
                    'disabled'   => !$flagRange->hasRemainingCapacity(),
                ]
            );
        }
    }

    /**
     * @param OptionsResolver $resolver
     *
     * @throws AccessException
     */
    final public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'data_class' => ParticipantFlag::class,
                // 'attr' => ['class' => 'col-md-6'],
            ]
        );
    }

    final public function getName(): string
    {
        return 'calendar_participant_flag';
    }
}
