<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Web-admin form for a RegistrationFlagOffer (příznak v rozsahu —
 * jedna možnost ubytování, jedno tričko, jedna fakulta…).
 *
 * Price / deposit are MODIFIERS applied to the parent RegistrationOffer
 * — e.g. "+800 Kč" for hotel ubytování, "−1300 Kč" for vlastní stan.
 * Help-text in each field calls this out so the operator doesn't enter
 * absolute prices by mistake.
 */
final class RegistrationFlagOfferEditType extends AbstractType
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
            ->add('description', TextareaType::class, [
                'label'    => 'Popis',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('note', TextareaType::class, [
                'label'    => 'Interní poznámka',
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('price', IntegerType::class, [
                'label'    => 'Cena Δ (Kč)',
                'required' => false,
                'help'     => 'Modifikátor ceny k base rozsahu. Kladné = příplatek, záporné = sleva, 0 = beze změny.',
            ])
            ->add('depositValue', IntegerType::class, [
                'label'    => 'Záloha Δ (Kč)',
                'required' => false,
                'help'     => 'Modifikátor zálohy. Pokud má příznak vlastní záloha-modifikátor, sečte se se zálohou rozsahu.',
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
            'data_class' => RegistrationFlagOffer::class,
        ]);
    }
}
