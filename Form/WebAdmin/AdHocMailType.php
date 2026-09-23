<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCoreBundle\Form\Type\MailBodyType;
use OswisOrg\OswisCoreBundle\Form\Type\MailSubjectType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * „Nová zpráva" jedné přihlášce. Text (`body`) je Twig + HTML z editoru ({@see MailBodyType}); čistí
 * a vykresluje ho ParticipantManualMailer stejně jako hromadný mail. Volba `preview` = náhled vedle textu.
 */
final class AdHocMailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Předmět s „Vložit údaj" — tatáž nabídka proměnných jako text zprávy (i předmět je Twig).
            ->add('subject', MailSubjectType::class, [
                'label'       => 'Předmět',
                'required'    => true,
                'attr'        => ['maxlength' => 200],
                'constraints' => [
                    new NotBlank(message: 'Vyplň prosím předmět.'),
                    new Length(max: 200, maxMessage: 'Předmět může mít maximálně 200 znaků.'),
                ],
            ])
            ->add('body', MailBodyType::class, [
                'label'       => 'Text zprávy',
                'required'    => true,
                'preview'     => $options['preview'],
                'constraints' => [
                    new NotBlank(message: 'Vyplň prosím text zprávy.'),
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
            'preview'    => null,
        ]);
        $resolver->setAllowedTypes('preview', ['null', 'array']);
    }
}
