<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulář číselníku FUNKCÍ týmu ({@see StaffRole}) — řízení, jídelna, technika, vede aktivitu…
 *
 * Číselník je konfigurovatelný per nasazení (proto `type` je volný string, NE validovaný enum),
 * ale `appliesTo` je pevná trojice, protože podle ní se rozhoduje, kde se funkce nabízí:
 * v rozpisu celodenních SLUŽEB, u konkrétní AKTIVITY, nebo v obojím.
 */
final class StaffRoleEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Název', 'required' => true])
            ->add('shortName', TextType::class, ['label' => 'Krátký název', 'required' => false])
            ->add('type', TextType::class, [
                'label'    => 'Strojový klíč',
                'required' => false,
                'help'     => 'Volitelný stabilní kód (rizeni, jidelna, technika…). Nemusí se vyplňovat.',
            ])
            ->add('appliesTo', ChoiceType::class, [
                'label'   => 'Kde se funkce používá',
                'choices' => [
                    'Služba (rozpis služeb)' => StaffRole::APPLIES_SERVICE,
                    'Role u konkrétní aktivity'        => StaffRole::APPLIES_ACTIVITY,
                    'Obojí'                            => StaffRole::APPLIES_BOTH,
                ],
                'required' => true,
                'help'     => 'Řídí, kde se funkce nabízí — v rozpisu služeb, u aktivit, nebo všude.',
            ])
            ->add('color', TextType::class, [
                'label'    => 'Barva',
                'required' => false,
                'help'     => 'Hex (#d32f2f) — barevný proužek v rozpisu.',
                'attr'     => ['placeholder' => '#006FAD'],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StaffRole::class]);
    }
}
