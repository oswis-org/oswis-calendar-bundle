<?php
/**
 * @noinspection UnknownInspectionInspection
 * @noinspection HtmlUnknownTarget
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use OswisOrg\OswisAddressBookBundle\Form\StudentPersonType;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagType;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RegistrationFormType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     *
     * @throws PriceInvalidArgumentException|OswisException
     */
    final public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $participant = $builder->getData();
        if (!($participant instanceof Participant) || !(($range = $participant->getRange()) instanceof RegistrationsRange)) {
            throw new PriceInvalidArgumentException('[nepodařilo se vytvořit účastníka]');
        }
        $participantType = $range->getParticipantType();
        $event = $range->getEvent();
        if (null === $participantType || null === $event) {
            $message = null === $participantType ? '[typ účastníka nenastaven]' : '';
            $message .= null !== $event ? '[událost nenastavena]' : '';
            throw new PriceInvalidArgumentException($message);
        }
        self::addContactField($builder);
        $this->addFlagTypeFields($builder, $range);
        self::addParticipantNotesFields($builder);
        self::addGdprField($builder);
        self::addSubmitButton($builder);
    }

    public static function addContactField(FormBuilderInterface $builder): void
    {
        $builder->add('contact', StudentPersonType::class, array('label' => 'Účastník', 'required' => true));
    }

    public function addFlagTypeFields(FormBuilderInterface $builder, RegistrationsRange $range): void
    {
        foreach ($range->getFlagsAggregatedByType(null, null, true, false) as $flagsOfType) {
            self::addFlagField($builder, $range, $flagsOfType[array_key_first($flagsOfType)]['flagType'], $flagsOfType);
        }
    }

    public static function addFlagField(
        FormBuilderInterface $builder,
        RegistrationsRange $range,
        ?ParticipantFlagType $flagType,
        array $flagsOfType
    ): void {
        $flagTypeSlug = $flagType ? $flagType->getSlug() : '0';
        $choices = [];
        foreach ($flagsOfType as $item) {
            if ($item['flag'] ?? false) {
                $choices[] = $item['flag'];
            }
        }
        $builder->add(
            "flag_$flagTypeSlug",
            ChoiceType::class,
            [
                'label'        => $flagType ? $flagType->getName() : 'Ostatní příznaky',
                'help'         => $flagType ? $flagType->getDescription() : 'Ostatní příznaky, které nespadají do žádné kategorie.',
                'required'     => $flagType ? $flagType->getMinInParticipant() > 1 : false,
                'choices'      => $choices,
                'mapped'       => false,
                'expanded'     => false,
                'multiple'     => false,
                'choice_label' => fn(ParticipantFlag $flag, $key, $value) => self::getFlagNameWithPrice($range, $flag),
                'choice_attr'  => fn(ParticipantFlag $flag, $key, $value) => self::getFlagAttributes($range, $flag),
                'group_by'     => fn(ParticipantFlag $flag, $key, $value) => self::getFlagGroupName($range, $flagType, $flag),
            ]
        );
    }

    /**
     * Get flag name and include (only non-zero) price.
     *
     * @param RegistrationsRange $registrationsRange
     * @param ParticipantFlag    $flag
     *
     * @return string
     */
    public static function getFlagNameWithPrice(RegistrationsRange $registrationsRange, ParticipantFlag $flag): string
    {
        $flagRange = $registrationsRange->getFlagRange($flag);
        $price = $flagRange ? $flagRange->getPrice() : null;
        $priceString = 0 !== $price ? ' ['.($price > 0 ? '+' : '').$price.',- Kč]' : '';

        return $flag->getName().$priceString;
    }

    public static function getFlagAttributes(RegistrationsRange $range, ParticipantFlag $flag): array
    {
        $attributes = [];
        if (0 === $range->getFlagRemainingCapacity($flag, true, false)) {
            $attributes['disabled'] = 'disabled';
        }

        return $attributes;
    }

    public static function getFlagGroupName(RegistrationsRange $range, ?ParticipantFlagType $flagType, ParticipantFlag $flag): ?string
    {
        if (null === $flagType) {
            return null;
        }
        if (ParticipantFlagType::TYPE_T_SHIRT === $flagType->getType()) {
            return self::getTShirtGroupName($flag);
        }

        return self::getFlagPriceGroup($range, $flag);
    }

    public static function getTShirtGroupName(ParticipantFlag $flag): string
    {
        if (strpos($flag->getName(), 'Pán') !== false) {
            return '♂ Pánské tričko';
        }
        if (strpos($flag->getName(), 'Dám') !== false) {
            return '♀ Dámské tričko';
        }
        if (strpos($flag->getName(), 'Uni') !== false) {
            return '⚲ Unisex tričko';
        }

        return '⚪ Ostatní';
    }

    public static function getFlagPriceGroup(RegistrationsRange $registrationsRange, ParticipantFlag $flag): string
    {
        $flagRange = $registrationsRange->getFlagRange($flag);
        $price = $flagRange ? $flagRange->getPrice() : null;
        if ($price > 0) {
            return '💰 S příplatkem';
        }
        if (null === $price || 0 === $price) {
            return '🆓 Bez příplatku';
        }

        return '⬤ Ostatní';
    }

    public static function addParticipantNotesFields(FormBuilderInterface $builder): void
    {
        $builder->add(
            'notes',
            CollectionType::class,
            array(
                'label'         => false,
                'entry_type'    => ParticipantNoteFormType::class,
                'entry_options' => array(
                    'label' => false,
                ),
            )
        );
    }

    public static function addGdprField(FormBuilderInterface $builder): void
    {
        $builder->add(
            'agreeGDPR',
            CheckboxType::class,
            array(
                'mapped'     => false,
                'label'      => 'Uvedením údajů potvrzuji souhlas s evidencí těchto dat.',
                'help'       => "Přečetl(a) jsem si a souhlasím s 
                                <a href='/gdpr' target='_blank'><i class='fas fa-user-secret'></i> podmínkami zpracování osobních údajů</a>.",
                'help_html'  => true,
                'required'   => true,
                'attr'       => [
                    'class' => 'custom-control-input',
                ],
                'label_attr' => [
                    'class' => 'custom-control-label',
                ],
            )
        );
    }

    public static function addSubmitButton(FormBuilderInterface $builder): void
    {
        $builder->add('save', SubmitType::class, ['label' => 'Registrovat se!', 'attr' => ['class' => 'btn-lg btn-primary btn-block'],]);
    }

    /**
     * @param OptionsResolver $resolver
     *
     * @throws AccessException
     */
    final public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            array(
                'data_class' => Participant::class,
            )
        );
    }

    public function getName(): string
    {
        return 'calendar_participant_registration';
    }
}
