<?php

namespace OswisOrg\OswisCalendarBundle\Form\EventParticipant;

use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantNote;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EventParticipantNoteType extends AbstractType
{
    final public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'textValue',
            TextareaType::class,
            array(
                'label'    => 'Poznámka',
                'required' => false,
                'help'     => 'Zde můžeš zadat svůj dotaz, poznámku nebo vzkaz pořadatelům.',
                'attr'     => ['placeholder' => false],
            )
        );
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
                'data_class' => EventParticipantNote::class,
//                'attr' => ['class' => 'col-md-6'],
            )
        );
    }

    final public function getName(): string
    {
        return 'calendar_event_participant_note';
    }
}
