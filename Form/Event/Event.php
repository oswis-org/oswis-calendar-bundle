<?php

namespace Zakjakub\OswisAddressBookBundle\Form;

use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Zakjakub\OswisAddressBookBundle\Entity\Organization;
use Zakjakub\OswisAddressBookBundle\Entity\Position;
use function assert;

class Event extends AbstractType
{
    final public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'organization',
            EntityType::class,
            array(
                'label'    => false,
                'required' => false,
                'class'    => Organization::class,
            )
        );

        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            static function (FormEvent $event) {
                $form = $event->getForm();
                $entity = $event->getData();
                assert($entity instanceof Organization);

                $form->add(
                    'organization',
                    EntityType::class,
                    array(
                        'label'         => false,
                        'required'      => false,
                        'class'         => Organization::class,
                        'query_builder' => static function (EntityRepository $repo) {
                            // TODO: Not only faculties! Add parameter to school - showInForm (if can be selected in forms).
                            return $repo->createQueryBuilder('organization')
                                ->where('organization.type = :faculty')
                                ->setParameter('faculty', 'faculty');
                        },
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
                'data_class' => Position::class,
//                'attr' => ['class' => 'col-md-6'],
            )
        );
    }

    final public function getName(): string
    {
        return 'address_book_school_position';
    }
}
