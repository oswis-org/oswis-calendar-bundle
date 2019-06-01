<?php

namespace Zakjakub\OswisAddressBookBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Zakjakub\OswisAddressBookBundle\Entity\ContactNote;
use function assert;

class EventParticipantNoteType extends AbstractType
{
    final public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(
                'content',
                TextType::class,
                array(
                    'label'    => 'Poznámka',
                    'required' => true,
                    'attr'     => ['placeholder' => false],
                )
            );

        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            static function (FormEvent $event) {
                $contactNote = $event->getData();
                assert($contactNote instanceof ContactNote);
                $form = $event->getForm();
                $form->add(
                    'content',
                    TextareaType::class,
                    array(
                        'label'    => $contactNote->isPublic() ? 'Poznámka pro účastníky' : 'Poznámka pro pořadatele',
                        'required' => false,
                        'help'     => $contactNote->isPublic() ? 'Poznámka, která bude zveřejněná účastníkům.' : 'Neveřejná poznámka, určená pouze pro pořadatele.',
                    )
                );
            }
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
                'data_class' => ContactNote::class,
//                'attr' => ['class' => 'col-md-6'],
            )
        );
    }

    final public function getName(): string
    {
        return 'address_book_contact_note';
    }
}
