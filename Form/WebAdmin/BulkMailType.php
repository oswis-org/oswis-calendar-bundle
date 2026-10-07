<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCoreBundle\Form\Type\MailBodyType;
use OswisOrg\OswisCoreBundle\Form\Type\MailSubjectType;
use OswisOrg\OswisCoreBundle\Mail\Rendering\MailRenderer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Zpráva jedné i více přihláškám (od 1. 10. 2026 i „Nová zpráva" z detailu přihlášky — 1 = N s N = 1) — vlastní text v editoru ({@see MailBodyType}), NEBO celá uložená kampaň.
 *
 * Do 28. 9. 2026 byla obrazovka ručně psané HTML a controller četl pole z POSTu sám (vlastní kontrola CSRF,
 * „předmět + text nebo kampaň" jednou společnou hláškou). Teď stejně jako šablony: CSRF a kontrola formulářem, chyba u pole, kterého se týká. O režimu rozhoduje server
 * (`mailMode`), ne to, zda JavaScript stihl vyprázdnit výběr kampaně.
 *
 * Volby: `campaigns` (název => slug uložených kampaní), `preview` (náhled vedle textu, viz {@see MailBodyType}).
 */
final class BulkMailType extends AbstractType
{
    public const string MODE_BODY = 'body';
    public const string MODE_TEMPLATE = 'template';
    /** Skupina kontrol při ukládání konceptu. */
    public const string GROUP_DRAFT = 'koncept';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $campaigns */
        $campaigns = $options['campaigns'];
        /** @var array<string, string> $testAddresses */
        $testAddresses = $options['testAddresses'];
        $builder
            // Snímek příjemců z výběru v seznamu přihlášek (ID oddělená čárkou) — nese se mezi kroky.
            ->add('idsCsv', HiddenType::class)
            // Koncept, ze kterého se píše (dávka 3.3): ID a revize, ze které autor vychází — ochrana proti souběhu.
            ->add('konceptId', HiddenType::class)
            ->add('konceptRevize', HiddenType::class)
            // Přílohy (dávka 3.5): JSON `[{id, mode}]` — spravuje ho seznam příloh na stránce (nahrání, příloha/odkaz).
            ->add('prilohy', HiddenType::class)
            ->add('subject', MailSubjectType::class, [
                'label'       => 'Předmět',
                'required'    => true,
                'constraints' => [
                    new NotBlank(message: 'Vyplň prosím předmět.'),
                    new Length(max: MailRenderer::SUBJECT_MAX_LENGTH, maxMessage: 'Předmět může mít nejvýš {{ limit }} znaků.', groups: ['Default', self::GROUP_DRAFT]),
                ],
            ])
            ->add('mailMode', ChoiceType::class, [
                'label'       => 'Co se pošle',
                'expanded'    => true,
                'choices'     => ['✍ Vlastní text' => self::MODE_BODY, 'Uložená kampaň' => self::MODE_TEMPLATE],
                // Přepínač jako skupina tlačítek (Bootstrap `btn-check` — motiv formulářů ho umí sám).
                'attr'        => ['class' => 'btn-group', 'role' => 'group', 'aria-label' => 'Co se pošle'],
                'label_attr'  => ['class' => 'btn btn-outline-primary btn-sm'],
                'choice_attr' => static fn (string $mode): array => ['class' => 'btn-check']
                    + (self::MODE_TEMPLATE === $mode && [] === $campaigns ? ['disabled' => 'disabled'] : []),
            ])
            ->add('templateSlug', ChoiceType::class, [
                'label'       => 'Uložená šablona (kampaň)',
                'required'    => false,
                'placeholder' => '— vyber kampaň —',
                'choices'     => $campaigns,
                'help'        => 'Odešle se celá uložená kampaň (vykreslená pro každého příjemce). Vlastní text se ignoruje.',
            ])
            // `required` = editor hlídá prázdný text už v prohlížeči (v režimu kampaně ne — třída
            // `mail-editor--template-mode`); o platnosti rozhoduje {@see overitRezim()}, ne NotBlank.
            // Naplánované odeslání (dávka 3.2). Prázdné = za 30 s — do té doby jde rozesílku zrušit.
            ->add('sendAt', DateTimeType::class, [
                'label'    => 'Odeslat v (nepovinné)',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'help'     => 'Prázdné = začne se odesílat za 30 sekund; do té doby i do zvoleného času jde rozesílku na stránce „Hromadné e-maily" zrušit.',
            ])
            ->add('body', MailBodyType::class, [
                'label'    => 'Text zprávy',
                'required' => true,
                'preview'  => $options['preview'],
            ])
            // Zkouška sobě (dávka 3.4, spec §5.3 a): jen adresa správce a zkušební schránky z Nastavení, nikdy libovolná.
            ->add('zkouskaAdresy', ChoiceType::class, [
                'label'    => 'Poslat zkoušku na',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices'  => $testAddresses,
                'data'     => array_slice(array_values($testAddresses), 0, 1),
            ])
            // Pro koho zkoušku vykreslit — příjemce vybraný v náhledu (skript ho sem přepíše); jinak první.
            ->add('zkouskaPro', HiddenType::class)
            ->add('poslatZkousku', SubmitType::class, [
                'label' => 'Poslat zkoušku',
                'attr'  => ['class' => 'btn btn-outline-secondary btn-sm'],
            ])
            // Uložit rozepsané (dávka 3.3) — i neúplné: kontroluje se jen délka předmětu (sloupec) a CSRF.
            ->add('ulozitKoncept', SubmitType::class, [
                'label' => 'Uložit koncept',
                'attr'  => ['class' => 'btn btn-outline-secondary', 'formnovalidate' => 'formnovalidate'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'  => null,
            'campaigns'   => [],
            'preview'     => null,
            'testAddresses' => [],
            'constraints' => [new Callback(self::overitRezim(...)), new Callback(self::overitZkousku(...))],
            // Koncept se uloží i neúplný — jen skupina `koncept` (délka předmětu); jinak plná kontrola.
            'validation_groups' => static fn (FormInterface $form): array => self::ukladaKoncept($form) ? [self::GROUP_DRAFT] : ['Default'],
        ]);
        $resolver->setAllowedTypes('campaigns', 'array');
        $resolver->setAllowedTypes('testAddresses', 'array');
        $resolver->setAllowedTypes('preview', ['null', 'array']);
    }

    /** Bylo kliknuto na „Poslat zkoušku"? */
    public static function posilaZkousku(FormInterface $form): bool
    {
        $button = $form->has('poslatZkousku') ? $form->get('poslatZkousku') : null;

        return $button instanceof ClickableInterface && $button->isClicked();
    }

    /**
     * Zkouška potřebuje aspoň jednu adresu (jinak se nekontroluje).
     *
     * @param array<string, mixed>|null $data
     */
    public static function overitZkousku(?array $data, ExecutionContextInterface $context): void
    {
        $form = $context->getRoot();
        if ($form instanceof FormInterface && self::posilaZkousku($form) && [] === ($data['zkouskaAdresy'] ?? [])) {
            $context->buildViolation('Zaškrtni, kam má zkouška odejít.')->atPath('[zkouskaAdresy]')->addViolation();
        }
    }

    /** Bylo kliknuto na „Uložit koncept"? */
    public static function ukladaKoncept(FormInterface $form): bool
    {
        $button = $form->has('ulozitKoncept') ? $form->get('ulozitKoncept') : null;

        return $button instanceof ClickableInterface && $button->isClicked();
    }

    /**
     * Vlastní text musí být vyplněný; kampaň vybraná. Druhé pole se v daném režimu nekontroluje ani nepoužije.
     *
     * @param array<string, mixed>|null $data
     */
    public static function overitRezim(?array $data, ExecutionContextInterface $context): void
    {
        $sendAt = $data['sendAt'] ?? null;
        if ($sendAt instanceof \DateTimeInterface && $sendAt < new \DateTimeImmutable()) {
            $context->buildViolation('Zvolený čas už minul — nech pole prázdné (odešle se hned), nebo vyber čas v budoucnu.')->atPath('[sendAt]')->addViolation();
        }
        if (self::MODE_TEMPLATE === ($data['mailMode'] ?? null)) {
            if (!is_string($data['templateSlug'] ?? null) || '' === $data['templateSlug']) {
                $context->buildViolation('Vyber uloženou kampaň.')->atPath('[templateSlug]')->addViolation();
            }

            return;
        }
        $body = $data['body'] ?? null;
        if (!is_string($body) || '' === trim($body)) {
            $context->buildViolation('Napiš text zprávy, nebo vyber uloženou kampaň.')->atPath('[body]')->addViolation();
        }
    }
}
