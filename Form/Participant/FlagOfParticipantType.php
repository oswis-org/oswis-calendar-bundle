<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Exception\AlreadySubmittedException;
use Symfony\Component\Form\Exception\LogicException;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FlagOfParticipantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'onPreSetData']);
    }

    /**
     * @param FormEvent $event
     *
     * @throws AlreadySubmittedException
     * @throws LogicException
     * @throws UnexpectedTypeException
     */
    public function onPreSetData(FormEvent $event): void
    {
        if (null === ($participantFlag = $event->getData()) || !($participantFlag instanceof ParticipantFlag)) {
            return;
        }
        if (null === ($flagRange = $participantFlag->getFlagOffer())
            || !($flagRange instanceof RegistrationFlagOffer)) {
            return;
        }
        $event->getForm()->add("flagRange", CheckboxType::class, [
            'label_html' => true,
            'label' => $flagRange->getExtendedName(),
            'required' => $flagRange->getMin() > 0,
            'value' => $flagRange,
            'disabled' => !$flagRange->hasRemainingCapacity(),
            'help_html' => true,
            'help' => $flagRange->getDescription(),
        ]);
        if ($participantFlag->getFlagOffer() && $participantFlag->getFlagOffer()->isFormValueAllowed()) {
            $event->getForm()->add("textValue", TextType::class, [
                'label_html' => true,
                'label' => $flagRange->getFormValueLabel(),
                'attr' => ['pattern' => $flagRange->getFormValueRegex()],
                'required' => $flagRange->getMin() > 0,
                'disabled' => !$flagRange->hasRemainingCapacity(),
            ]);
        }
    }

    /**
     * @param OptionsResolver $resolver
     *
     * @throws AccessException
     */
    final public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ParticipantFlag::class,
            // 'attr' => ['class' => 'col-md-6'],
        ]);
    }

    final public function getName(): string
    {
        return 'calendar_participant_flag';
    }
}
