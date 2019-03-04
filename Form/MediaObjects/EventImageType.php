<?php

namespace Zakjakub\OswisCalendarBundle\Form\MediaObjects;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;
use Zakjakub\OswisCalendarBundle\Entity\MediaObjects\EventImage;

final class EventImageType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'file',
            VichImageType::class,
            [
                'label'    => 'label.file',
                'required' => false,
            ]
        );
    }

    /**
     * @param OptionsResolver $resolver
     *
     * @throws \Symfony\Component\OptionsResolver\Exception\AccessException
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'data_class'      => EventImage::class,
                'csrf_protection' => false,
            ]
        );
    }

    /**
     * @return string|null
     */
    public function getBlockPrefix(): string
    {
        return 'calendar_event_image';
    }
}
