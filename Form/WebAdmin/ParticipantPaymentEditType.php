<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipantPaymentEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('numericValue', IntegerType::class, [
                'label'    => 'Částka (Kč)',
                'required' => false,
            ])
            ->add('dateTime', DateTimeType::class, [
                'label'    => 'Datum a čas platby',
                'widget'   => 'single_text',
                'required' => false,
            ])
            ->add('variableSymbol', TextType::class, [
                'label'    => 'Variabilní symbol',
                'required' => false,
            ])
            ->add('type', TextType::class, [
                'label'    => 'Typ platby (bank-transfer / card / cash / on-line / internal)',
                'required' => false,
            ])
            ->add('note', TextareaType::class, [
                'label'    => 'Poznámka (veřejná)',
                'required' => false,
            ])
            ->add('internalNote', TextareaType::class, [
                'label'    => 'Interní poznámka',
                'required' => false,
            ])
            ->add('errorMessage', TextareaType::class, [
                'label'    => 'Chybová zpráva (lze vymazat po vyřešení)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ParticipantPayment::class,
        ]);
    }
}
