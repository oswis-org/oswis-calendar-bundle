<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisAddressBookBundle\Entity\Place;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Web-admin form for editing the most-used Event fields.
 *
 * Scope: top-level descriptive + scheduling + capacity + visibility fields.
 * Complex sub-collections (images/files/flagConnections/subEvents) are left
 * to dedicated edit screens.
 */
final class EventEditType extends AbstractType
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
            ->add('startDate', DateTimeType::class, [
                'label'    => 'Začátek (datum a čas)',
                'required' => false,
                'widget'   => 'single_text',
                'mapped'   => false,
                'input'    => 'datetime',
            ])
            ->add('endDate', DateTimeType::class, [
                'label'    => 'Konec (datum a čas)',
                'required' => false,
                'widget'   => 'single_text',
                'mapped'   => false,
                'input'    => 'datetime',
            ])
            ->add('baseCapacity', IntegerType::class, [
                'label'    => 'Základní kapacita',
                'required' => false,
            ])
            ->add('fullCapacity', IntegerType::class, [
                'label'    => 'Plná kapacita',
                'required' => false,
            ])
            ->add('color', ColorType::class, [
                'label'    => 'Barva (HEX)',
                'required' => false,
            ])
            ->add('publicOnWeb', CheckboxType::class, [
                'label'    => 'Veřejné na webu',
                'required' => false,
            ])
            ->add('category', EntityType::class, [
                'label'        => 'Kategorie',
                'class'        => EventCategory::class,
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => '— bez kategorie —',
                'help'         => 'Typ aktivity / bloku / služby (řídí barvu a default časy).',
            ])
            ->add('place', EntityType::class, [
                'label'        => 'Místo',
                'class'        => Place::class,
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => '— bez místa —',
            ])
            ->add('placeText', TextType::class, [
                'label'    => 'Místo (text/upřesnění)',
                'required' => false,
                'help'     => 'Samostatné místo nebo upřesnění k vybranému místu (např. „Aula — sraz před vchodem").',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Event::class,
        ]);
    }
}
