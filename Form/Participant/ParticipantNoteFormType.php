<?php

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipantNoteFormType extends AbstractType
{
    final public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('textValue', TextareaType::class, [
            'label' => 'Poznámka',
            'required' => false,
            'help' => 'Zde můžeš zadat svůj dotaz, poznámku nebo vzkaz pořadatelům.',
            'attr' => ['placeholder' => false],
        ]);
    }

    /**
     * @param OptionsResolver $resolver
     *
     * @throws AccessException
     */
    final public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ParticipantNote::class,
            //                'attr' => ['class' => 'col-md-6'],
        ]);
    }

    final public function getName(): string
    {
        return 'calendar_participant_note';
    }
}
