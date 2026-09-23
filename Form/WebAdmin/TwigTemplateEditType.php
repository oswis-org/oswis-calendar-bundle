<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use OswisOrg\OswisCoreBundle\Form\Type\MailBodyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TwigTemplateEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Název', 'required' => false])
            ->add('shortName', TextType::class, ['label' => 'Krátký název', 'required' => false])
            // Pole je navázané na `forcedSlug`, ne na `slug`: `setSlug()` je jím přebíjené, takže
            // editace `slug` u řádku s vyplněným `forcedSlug` (což je 31 z 34 šablon) NIC nedělala.
            ->add('forcedSlug', TextType::class, [
                'label'    => 'Slug (adresa šablony)',
                'required' => false,
                'help'     => 'Podle sluga se šablona hledá — musí být jedinečný. Při shodě by se použila ta starší.',
            ])
            ->add('kind', ChoiceType::class, [
                'label'       => 'Druh',
                'required'    => false,
                'placeholder' => '— neurčeno —',
                'choices'     => [
                    'Systémový (transakční)' => TwigTemplate::KIND_SYSTEM,
                    'Kampaň (celý e-mail)'   => TwigTemplate::KIND_CAMPAIGN,
                    'Blok / snippet'         => TwigTemplate::KIND_SNIPPET,
                    'Stránka (web)'          => TwigTemplate::KIND_PAGE,
                    'PDF'                    => TwigTemplate::KIND_PDF,
                ],
                'help' => 'Kampaň = celý e-mail (infomail/feedback); Snippet = znovupoužitelný blok k vložení do těla.',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Popis',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('regularTemplateName', TextType::class, [
                'label' => 'Twig cesta (např. @OswisOrgOswisCalendar/e-mail/pages/participant-summary.html.twig)',
                'required' => false,
                'help' => 'Pokud prázdné, použije se shoda podle sluga.',
            ])
            // Editor mailu přes SPOLEČNÝ formulářový typ, ne ručním vložením partialu: typ se stará
            // o jméno, id i navázání pole na formulář. Ruční vložení mě dnes stálo tři vady —
            // shozený CSRF token, tentýž obsah na stránce dvakrát a vlastní obsluhu náhledu.
            ->add('textValue', MailBodyType::class, [
                'label'    => 'Obsah e-mailu',
                'required' => false,
                'rows'     => 18,
                'help'     => 'Použije se jen tehdy, když není vyplněná Twig cesta.',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TwigTemplate::class]);
    }
}
