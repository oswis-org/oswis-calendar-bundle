<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\CheckIn\CheckInStation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Web-admin formulář konfigurace check-in stanice ({@see CheckInStation}) per turnus.
 *
 * Sada stanic je KONFIGUROVATELNÁ (rozhodnutí usera 2026-07-13: „kolik stanic = na místě") —
 * tenhle formulář je nástroj, kterým si tým sadu z 7 kindů složí/upraví. `event` se nastavuje
 * v controlleru (ne přes formulář — IRI/relace). `valueOptions` (JSON list) se edituje jako textarea
 * (řádek = volba) přes NEmapované pole `valueOptionsText` — převod řeší controller.
 */
final class CheckInStationEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'    => 'Název stanice',
                'required' => true,
                'help'     => 'Co uvidí tým na dlaždici (např. „Evidence", „Pásky", „Ubytování").',
            ])
            ->add('stationKind', ChoiceType::class, [
                'label'   => 'Druh',
                'choices' => [
                    'Evidence (příjezd; hledá v plném seznamu, zapisuje příjezd)' => CheckInStation::KIND_EVIDENCE,
                    'Pásky (vydání pásku skupiny)'                                => CheckInStation::KIND_WRISTBAND,
                    'Ubytování (přiřazení pokoje; vyžaduje online)'               => CheckInStation::KIND_ACCOMMODATION,
                    'Strava / stravenky'                                         => CheckInStation::KIND_FOOD,
                    'Tričko (výdej velikosti)'                                   => CheckInStation::KIND_TSHIRT,
                    'Bezpečnost (podpis)'                                         => CheckInStation::KIND_SAFETY,
                    'Obecná (jen „hotovo" + volitelná hodnota)'                  => CheckInStation::KIND_GENERIC,
                ],
                'help'    => 'Řídí chování a výchozí ikonu. Univerzální — poskládej vlastní sadu.',
            ])
            ->add('orderNumber', IntegerType::class, [
                'label'    => 'Pořadí',
                'required' => false,
                'help'     => 'Pořadí dlaždice v hubu (nižší = dřív).',
            ])
            ->add('icon', TextType::class, [
                'label'    => 'Ikona (nepovinné)',
                'required' => false,
                'help'     => 'Název ion-icon (např. „bed-outline"). Prázdné = výchozí dle druhu.',
            ])
            ->add('capturesValue', CheckboxType::class, [
                'label'    => 'Zachytává hodnotu',
                'required' => false,
                'help'     => 'Např. velikost trička, barva pásku, číslo pokoje.',
            ])
            ->add('valueLabel', TextType::class, [
                'label'    => 'Popisek hodnoty',
                'required' => false,
                'help'     => 'Např. „Vydaná velikost", „Číslo pokoje". Jen když zachytává hodnotu.',
            ])
            ->add('valueOptionsText', TextareaType::class, [
                'label'    => 'Volby hodnoty (řádek = volba)',
                'required' => false,
                'mapped'   => false,
                'attr'     => ['rows' => 4, 'placeholder' => "S\nM\nL\nXL\nXXL"],
                'help'     => 'Číselník voleb (jedna na řádek). Prázdné = volný text.',
            ])
            ->add('requiresOnline', CheckboxType::class, [
                'label'    => 'Vyžaduje online',
                'required' => false,
                'help'     => 'Sdílený zdroj (kapacita ubytování). U ubytování se vynutí automaticky.',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CheckInStation::class,
        ]);
    }
}
