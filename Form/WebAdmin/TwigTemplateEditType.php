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
            // Rodič šablony: `{% extends %}` se do zdroje doplní sám (TwigTemplate::slozitZdroj). Do 23. 9. 2026
            // pole znamenalo „místo obsahu" a text z databáze se u vyplněné cesty nepoužil vůbec.
            // Výběr, ne ručně psaná cesta: překlep by znamenal šablonu, která nejde vykreslit. Nabídku
            // skládají poskytovatelé v bundlech (MailParentRegistry); neznámá hodnota v ní zůstane jako „jiné: …".
            ->add('regularTemplateName', ChoiceType::class, [
                'label'       => 'Vychází z',
                'required'    => false,
                'choices'     => $options['rodice'],
                'placeholder' => '— nic: samostatný text, nebo blok k vložení —',
                'help'        => 'Obálka, ze které šablona vychází. Obsah níže pak přepisuje jen její bloky; když ho necháš prázdný, odejde mail přesně podle obálky.',
            ])
            // Editor mailu přes SPOLEČNÝ formulářový typ, ne ručním vložením partialu: typ se stará
            // o jméno, id i navázání pole na formulář. Ruční vložení mě dnes stálo tři vady —
            // shozený CSRF token, tentýž obsah na stránce dvakrát a vlastní obsluhu náhledu.
            ->add('textValue', MailBodyType::class, [
                'label'    => 'Obsah e-mailu',
                'required' => false,
                'rows'     => 18,
                'help'     => 'U šablony, která z něčeho vychází, patří text jen do bloků — mimo ně by shodil odeslání.',
                'preview'  => $options['preview'],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // `preview` = konfigurace sjednoceného náhledu pro editor (dodává kontroler — zná routy a vzorové příjemce).
        // `rodice` = nabídka obálek (popisek → Twig jméno) pro „Vychází z" — dodává kontroler z MailParentRegistry.
        $resolver->setDefaults(['data_class' => TwigTemplate::class, 'preview' => null, 'rodice' => []]);
        $resolver->setAllowedTypes('preview', ['null', 'array']);
        $resolver->setAllowedTypes('rodice', 'array');
    }
}
