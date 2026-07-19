<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Event\EventSection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Informační sekce programu (spec 2026-06-12: EventSection = event FK + NameableTrait + TextValueTrait
 * + PriorityTrait + EntityPublicTrait + icon). Slouží pro instruktorskou patičku PDF i obsahové
 * stránky v appce (NÁVŠTĚVY / TÝM / BALENÍ / info před akcí). Per turnus, bez dědičnosti.
 */
final class EventSectionEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'    => 'Nadpis sekce',
                'required' => false,
                'help'     => 'Např. „BALENÍ", „NÁVŠTĚVY", „Info před akcí".',
            ])
            ->add('textValue', TextareaType::class, [
                'label'    => 'Obsah',
                'required' => false,
                'attr'     => ['rows' => 6],
            ])
            ->add('icon', TextType::class, [
                'label'    => 'Ikona (Iconify)',
                'required' => false,
                'help'     => 'Volitelně: název ikony (Iconify), např. „tabler:info-circle".',
            ])
            ->add('priority', IntegerType::class, [
                'label'    => 'Pořadí',
                'required' => false,
                'help'     => 'Vyšší číslo = výš. Prázdné = 0.',
            ])
            ->add('publicInApp', CheckboxType::class, [
                'label'    => 'Veřejné v aplikaci',
                'required' => false,
            ])
            ->add('publicOnWeb', CheckboxType::class, [
                'label'    => 'Veřejné na webu',
                'required' => false,
            ])
            ->add('note', TextareaType::class, [
                'label'    => 'Interní poznámka (jen pro tým)',
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventSection::class,
        ]);
    }
}
