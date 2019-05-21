<?php

namespace Zakjakub\OswisCalendarBundle\Form\EventParticipant;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendee;
use Zakjakub\OswisAddressBookBundle\Form\StudentPersonType;

class EventParticipantType extends AbstractType
{
    final public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add(
                'contact',
                StudentPersonType::class,
                array('label' => false, 'required' => true)
            )
            ->add(
                'events',
                CollectionType::class,
                array(
                    'label'         => 'Výběr akcí k přihlášení',
                    'entry_type'    => SubEventAttendeeType::class,
                    'entry_options' => array(
                        'label' => false,
                        'attr'  => [
                            'label' => false,
                            'class' => 'event_attendee_events',
                        ],
                    ),
                )
            )
            ->add(
                'question',
                TextareaType::class,
                array(
                    'mapped'   => false,
                    'label'    => 'Dotaz zaměstnavatelům',
                    'help'     => 'Položte dotaz všem zaměstnavatelům.',
                    'required' => false,
                )
            )
            ->add(
                'agreeGDPR',
                CheckboxType::class,
                array(
                    'mapped'     => false,
                    'label'      => 'Uvedením údajů potvrzuji souhlas s evidencí těchto dat.',
                    // 'help'     => 'Tyto slouží pouze k interním účelům pořadatele akce. Údaje nebudou zveřejňovány ani předávány třetí osobě.',
                    'required'   => true,
                    'attr'       => [
                        'class' => 'custom-control-input',
                    ],
                    'label_attr' => [
                        'class' => 'custom-control-label',
                    ],
                )
            )
            ->add(
                'verification',
                TextType::class,
                array(
                    'mapped'   => false,
                    'label'    => false,
                    'required' => false,
                    'attr'     => ['class' => 'form-verification'],
                )
            )
            ->add(
                'verificationCode',
                TextType::class,
                array(
                    'mapped'   => false,
                    'label'    => false,
                    'required' => false,
                    'attr'     => ['class' => 'form-verification-code'],
                )
            )
            ->add(
                'save',
                SubmitType::class,
                array(
                    'label' => 'Registrovat se!',
                    'attr'  => ['class' => 'btn-lg btn-primary btn-block'],
                )
            );
    }

    /**
     * @param OptionsResolver $resolver
     *
     * @throws \Symfony\Component\OptionsResolver\Exception\AccessException
     */
    final public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            array(
                'data_class' => EventAttendee::class,
            )
        );
    }

    final public function getName(): string
    {
        return 'job_fair_event_attendee';
    }
}
