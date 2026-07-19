<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisAddressBookBundle\Entity\Place;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantGroup;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
            // Zařazení do bloku (rotace) — přesun/přiřazení aktivity pod nadakci. Nabídku bloků plní
            // kontroler (bloky turnusu), non-mapped (superEvent nastavuje kontroler ručně + hlídá hloubku).
            ->add('parentBlock', EntityType::class, [
                'label'        => 'Blok (rotace)',
                'class'        => Event::class,
                'choices'      => $options['blocks'],
                'choice_label' => 'name',
                'required'     => false,
                'mapped'       => false,
                'placeholder'  => '— na úrovni dne (mimo blok) —',
                'help'         => 'Zařadit aktivitu jako podakci bloku (rotace), nebo ji nechat samostatně v programu dne.',
            ])
            // Cílová skupina (pásek) — rotační sloty. Nabídka se plní z kontroleru (pásky turnusu/
            // ročníku), aby form nedělal vlastní dotaz (IDOR) a při 0 páscích byl prázdný, ne rozbitý.
            ->add('targetGroup', EntityType::class, [
                'label'        => 'Cílová skupina (pásek)',
                'class'        => ParticipantGroup::class,
                'choices'      => $options['groups'],
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => '— všichni (mimo rotaci) —',
                'help'         => 'Jen u rotačního slotu — komu je slot určen (např. MODRÁ). Prázdné = pro všechny.',
            ])
            // Programová pole aktivity (spec 2026-06-12 krok 4). Dosud šla nastavit jen přes API —
            // teď i z web adminu (editor programu / obecná editace události).
            ->add('signupMode', ChoiceType::class, [
                'label'   => 'Přihlašování',
                'choices' => [
                    'Bez přihlašování (jen položka programu)'        => Event::SIGNUP_MODE_NONE,
                    'Dobrovolné (účastník si přidá do svého programu)' => Event::SIGNUP_MODE_OPTIONAL,
                    'Povinné přihlášení v aplikaci (hlídá kapacitu)'  => Event::SIGNUP_MODE_REQUIRED,
                    'Zapisuje tým osobně (nástěnka/kiosek)'           => Event::SIGNUP_MODE_STAFF,
                ],
                'required' => true,
                'help'     => 'Jak se účastník na aktivitu dostane.',
            ])
            ->add('signupNote', TextType::class, [
                'label'    => 'Poznámka k zápisu',
                'required' => false,
                'help'     => 'Kde/jak se zapisuje — zobrazí se u aktivity (např. „Registrace v kiosku v hotovosti").',
            ])
            ->add('signupDeadline', DateTimeType::class, [
                'label'    => 'Uzávěrka přihlašování',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime',
            ])
            ->add('price', IntegerType::class, [
                'label'    => 'Cena (Kč)',
                'required' => false,
                'help'     => 'Placené aktivity (typicky hotově v kiosku). Prázdné = zdarma.',
            ])
            ->add('highlight', CheckboxType::class, [
                'label'    => 'Zvýraznit v programu',
                'required' => false,
            ])
            ->add('publicInApp', CheckboxType::class, [
                'label'    => 'Veřejné v aplikaci',
                'required' => false,
                'help'     => 'Vypnuté = interní (služby, týmové body) — účastník aktivitu v appce nevidí.',
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
            // Nabídka pásků (ParticipantGroup) pro pole targetGroup; naplní kontroler dle turnusu.
            'groups'     => [],
            // Nabídka bloků (Event program-block) pro pole parentBlock; naplní kontroler dle turnusu.
            'blocks'     => [],
        ]);
        $resolver->setAllowedTypes('groups', 'array');
        $resolver->setAllowedTypes('blocks', 'array');
    }
}
