<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ParticipantMailCategoryEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Název', 'required' => false])
            ->add('shortName', TextType::class, ['label' => 'Krátký název', 'required' => false])
            ->add('slug', TextType::class, [
                'label'    => 'Slug',
                'required' => false,
                'help'     => 'Pozor: změna sluga rozbije link.',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Popis',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('type', TextType::class, [
                'label' => 'Typ (např. summary, activation-request, payment)',
                'required' => false,
            ])
            ->add('priority', IntegerType::class, [
                'label'    => 'Priorita',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ParticipantMailCategory::class]);
    }
}
