<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Event\ProgramDay;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Den programu (spec 2026-06-12: „ProgramDay = event(turnus) FK + date + NameableTrait").
 * Datum den seskupí aktivity v přehledu (aktivita padne pod den se shodným datem startu);
 * name = label („PŘÍJEZDOVÝ DEN"), description = veřejná poznámka dne, note = interní.
 */
final class ProgramDayEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'label'    => 'Datum',
                'required' => true,
                'widget'   => 'single_text',
                'input'    => 'datetime',
                'help'     => 'Podle data se pod tento den v přehledu seskupí aktivity se stejným datem startu.',
            ])
            ->add('name', TextType::class, [
                'label'    => 'Název dne',
                'required' => false,
                'help'     => 'Např. „PŘÍJEZDOVÝ DEN", „TŘETÍ DEN". Prázdné = jen datum.',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Veřejná poznámka dne',
                'required' => false,
                'attr'     => ['rows' => 2],
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
            'data_class' => ProgramDay::class,
        ]);
    }
}
