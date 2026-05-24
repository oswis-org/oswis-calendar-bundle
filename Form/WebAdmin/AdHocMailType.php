<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Web-admin form for composing an ad-hoc e-mail to a participant.
 *
 * `body` is treated as trusted HTML — sanitized in the controller via
 * symfony/html-sanitizer before being passed to the Twig template.
 */
final class AdHocMailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', TextType::class, [
                'label'       => 'Předmět',
                'required'    => true,
                'constraints' => [
                    new NotBlank(message: 'Vyplň prosím předmět.'),
                    new Length(max: 200, maxMessage: 'Předmět může mít maximálně 200 znaků.'),
                ],
            ])
            ->add('body', TextareaType::class, [
                'label'       => 'Tělo zprávy (HTML povoleno: <p>, <a>, <strong>, <em>, <br>, <ul>, <ol>, <li>)',
                'required'    => true,
                'attr'        => ['rows' => 12, 'style' => 'font-family: monospace;'],
                'constraints' => [
                    new NotBlank(message: 'Vyplň prosím tělo zprávy.'),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Odeslat e-mail',
                'attr'  => ['class' => 'btn btn-primary'],
                'validate' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
