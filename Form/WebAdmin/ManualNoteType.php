<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationChannel;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationDirection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Admin-side form for logging a phone call, chat snapshot, or other manual
 * communication entry into the participant timeline.
 */
final class ManualNoteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('channel', EnumType::class, [
                'class'        => CommunicationChannel::class,
                'choice_label' => static fn (CommunicationChannel $c): string => $c->label(),
                'choices'      => [
                    CommunicationChannel::PHONE,
                    CommunicationChannel::CHAT,
                    CommunicationChannel::INCOMING_MAIL,
                ],
                'label'        => 'Kanál',
                'required'     => true,
                'constraints'  => [new NotNull(message: 'Vyber prosím kanál.')],
            ])
            ->add('direction', EnumType::class, [
                'class'        => CommunicationDirection::class,
                'choice_label' => static fn (CommunicationDirection $d): string => $d->label(),
                'label'        => 'Směr',
                'required'     => true,
                'data'         => CommunicationDirection::IN,
                'constraints'  => [new NotNull(message: 'Vyber prosím směr.')],
            ])
            ->add('occurredAt', DateTimeType::class, [
                'label'       => 'Kdy proběhlo',
                'required'    => true,
                'widget'      => 'single_text',
                'input'       => 'datetime',
                'constraints' => [new NotNull(message: 'Vyplň prosím datum a čas.')],
            ])
            ->add('subject', TextType::class, [
                'label'       => 'Téma (krátký nadpis)',
                'required'    => false,
                'constraints' => [new Length(max: 200, maxMessage: 'Maximálně 200 znaků.')],
            ])
            ->add('otherPartyName', TextType::class, [
                'label'    => 'Jméno protistrany',
                'required' => false,
                'help'     => 'Default: jméno účastníka.',
            ])
            ->add('otherPartyContact', TextType::class, [
                'label'    => 'Kontakt protistrany (telefon / handle)',
                'required' => false,
            ])
            ->add('durationSec', IntegerType::class, [
                'label'    => 'Délka (sekundy)',
                'required' => false,
                'attr'     => ['min' => 0],
            ])
            ->add('body', TextareaType::class, [
                'label'       => 'Záznam / shrnutí',
                'required'    => true,
                'attr'        => ['rows' => 8],
                'constraints' => [new NotBlank(message: 'Vyplň prosím shrnutí.')],
            ])
            ->add('internal', CheckboxType::class, [
                'label'    => 'Interní (neviditelné pro účastníka)',
                'required' => false,
                'data'     => true,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit záznam',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
