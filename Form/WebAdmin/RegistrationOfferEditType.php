<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Web-admin form for editing a RegistrationOffer (registrační rozsah)
 * — top-level descriptive + pricing + capacity + window + visibility.
 *
 * Price / depositValue map to the offer's OWN values (not the recursive
 * sum through requiredRegRange). Operator works directly with the
 * per-row value; the runtime renderer handles parent inheritance.
 */
final class RegistrationOfferEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'    => 'Název',
                'required' => false,
            ])
            ->add('shortName', TextType::class, [
                'label'    => 'Krátký název',
                'required' => false,
            ])
            ->add('slug', TextType::class, [
                'label'    => 'Slug (URL)',
                'required' => false,
                'help'     => 'POZOR: změna sluga rozbije staré odkazy.',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Popis',
                'required' => false,
                'attr'     => ['rows' => 4],
            ])
            ->add('note', TextareaType::class, [
                'label'    => 'Interní poznámka',
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('startDateTime', DateTimeType::class, [
                'label'    => 'Otevření přihlášek',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime',
            ])
            ->add('endDateTime', DateTimeType::class, [
                'label'    => 'Uzavření přihlášek',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime',
                'mapped'   => false,
            ])
            ->add('price', IntegerType::class, [
                'label'    => 'Cena (Kč)',
                'required' => false,
                'help'     => 'Vlastní cena tohoto rozsahu. Pokud rozsah dědí cenu z nadřazeného (vyžaduje), nechte prázdné.',
            ])
            ->add('depositValue', IntegerType::class, [
                'label'    => 'Záloha (Kč)',
                'required' => false,
                'help'     => 'Vlastní záloha tohoto rozsahu. Dědí z nadřazeného pokud prázdné.',
            ])
            ->add('baseCapacity', IntegerType::class, [
                'label'    => 'Základní kapacita',
                'required' => false,
            ])
            ->add('fullCapacity', IntegerType::class, [
                'label'    => 'Plná kapacita',
                'required' => false,
            ])
            ->add('publicOnWeb', CheckboxType::class, [
                'label'    => 'Veřejné na webu',
                'required' => false,
            ])
            ->add('publicInApp', CheckboxType::class, [
                'label'    => 'Veřejné v aplikaci',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationOffer::class,
        ]);
    }
}
