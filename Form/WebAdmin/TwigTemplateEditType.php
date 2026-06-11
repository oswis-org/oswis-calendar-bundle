<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
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
            ->add('slug', TextType::class, ['label' => 'Slug', 'required' => false])
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
            ->add('textValue', TextareaType::class, [
                'label'    => 'Inline obsah šablony (pouze pokud Twig cesta není zadána)',
                'required' => false,
                'attr'     => ['rows' => 8, 'style' => 'font-family: monospace;'],
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
