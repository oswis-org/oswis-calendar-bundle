<?php
/**
 * @noinspection UnknownInspectionInspection
 * @noinspection HtmlUnknownTarget
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use OswisOrg\OswisAddressBookBundle\Form\StudentPersonType;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Service\EventService;
use OswisOrg\OswisCalendarBundle\Service\RegistrationService;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
    protected EventService $eventService;

    protected RegistrationService $registrationService;

    public function __construct(EventService $eventService, RegistrationService $registrationService)
    {
        $this->eventService = $eventService;
        $this->registrationService = $registrationService;
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     *
     * @throws PriceInvalidArgumentException
     */
    final public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $participant = $builder->getData();
        if (!($participant instanceof Participant)) {
            throw new PriceInvalidArgumentException('[účastník neexistuje]');
        }
        $participantType = $participant->getParticipantType();
        $event = $participant->getRegistrationsRange();
        if (null === $participantType || null === $event) {
            $message = null === $participantType ? '[typ účastníka nezadán]' : '';
            $message .= null !== $event ? '[událost nezadána]' : '';
            throw new PriceInvalidArgumentException($message);
        }
        $this->addEventField($builder, $event, $participantType);
        self::addParticipantTypeField($builder, $event, $participantType);
        self::addContactField($builder);
        $this->addFlagTypeFields($builder, $event, $participant);
        self::addParticipantNotesFields($builder);
        self::addGdprField($builder);
        self::addSubmitButton($builder);
    }

    public function addEventField(FormBuilderInterface $builder, Event $event, ParticipantType $participantType): void
    {
        $builder->add(
            'event',
            EntityType::class,
            [
                'class'        => Event::class,
                'label'        => 'Akce',
                'required'     => true,
                'choices'      => [$event],
                'data'         => $event,
                'attr'         => ['readonly' => 'readonly'],
                'choice_label' => fn(Event $e, $key, $value) => $this->getEventLabel($e, $participantType),
            ]
        );
    }

    public function getEventLabel(Event $event, ParticipantType $participantType): string
    {
        $label = $event->getExtendedName(true, true, $participantType);
        $label .= !$event->isRegistrationsAllowed($participantType) ? ' (přihlášky nejsou povoleny)' : null;
        $label .= $this->registrationService->getRemainingCapacity($event, $participantType) === 0 ? ' (překročena kapacita)' : null;

        return $label;
    }

    public static function addParticipantTypeField(FormBuilderInterface $builder, Event $event, ParticipantType $participantType): void
    {
        $builder->add(
            'participantType',
            EntityType::class,
            [
                'class'        => ParticipantType::class,
                'label'        => 'Typ účastníka',
                'required'     => true,
                'choices'      => $event->getParticipantTypes(null, true),
                'data'         => $participantType,
                'attr'         => ['readonly' => 'readonly'],
                'choice_label' => fn(ParticipantType $type, $key, $value) => $type->getName(),
            ]
        );
    }

    public static function addContactField(FormBuilderInterface $builder): void
    {
        $builder->add('contact', StudentPersonType::class, array('label' => 'Účastník', 'required' => true));
    }

    public function addFlagTypeFields(FormBuilderInterface $builder, Event $event, Participant $participant): void
    {
        foreach ($event->getAllowedFlagsAggregatedByType($participant->getParticipantType(), true) as $flagsOfType) {
            self::addFlagField(
                $builder,
                $flagsOfType['flagType'],
                $flagsOfType['flags'],
                $this->registrationService,
                $participant->getParticipantType(),
                $event
            );
        }
    }

    public static function addFlagField(
        FormBuilderInterface $builder,
        ?ParticipantFlagType $flagType,
        array $flags,
        RegistrationService $registrationService,
        ParticipantType $participantType,
        Event $event
    ): void {
        $flagTypeSlug = $flagType ? $flagType->getSlug() : '0';
        $builder->add(
            "flag_$flagTypeSlug",
            ChoiceType::class,
            [
                'label'        => $flagType ? $flagType->getName() : 'Ostatní příznaky',
                'help'         => $flagType ? $flagType->getDescription() : 'Ostatní příznaky, které nespadají do žádné kategorie.',
                'required'     => $flagType ? $flagType->getMinInParticipant() > 1 : false,
                'choices'      => $flags,
                'mapped'       => false,
                'expanded'     => false,
                'multiple'     => false,
                'choice_label' => fn(ParticipantFlag $flag, $key, $value) => self::getFlagNameWithPrice($flag),
                'choice_attr'  => fn(ParticipantFlag $flag, $key, $value) => self::getFlagAttributes($flag, $participantType, $registrationService, $event),
                'group_by'     => fn(ParticipantFlag $flag, $key, $value) => self::getFlagGroupName($flagType, $flag),
            ]
        );
    }

    /**
     * Get flag name and include (only non-zero) price.
     *
     * @param ParticipantFlag $flag
     *
     * @return string
     */
    public static function getFlagNameWithPrice(ParticipantFlag $flag): string
    {
        $price = ' ['.($flag->getPrice() > 0 ? '+' : '').$flag->getPrice().',- Kč]';

        return $flag->getName().(0 !== $flag->getPrice() ? $price : '');
    }

    public static function getFlagAttributes(
        ParticipantFlag $flag,
        ParticipantType $participantType,
        RegistrationService $registrationService,
        Event $event
    ): array {
        $attributes = [];
        if (self::isFlagCapacityExhausted($flag, $participantType, $registrationService, $event)) {
            $attributes['disabled'] = 'disabled';
        }

        return $attributes;
    }

    public static function isFlagCapacityExhausted(
        ParticipantFlag $flag,
        ParticipantType $participantType,
        RegistrationService $registrationService,
        Event $event
    ): bool {
        return $registrationService->getParticipantFlagRemainingCapacity($event, $flag, $participantType) === 0;
    }

    public static function getFlagGroupName(?ParticipantFlagType $flagType, ParticipantFlag $flag): ?string
    {
        if (null === $flagType) {
            return null;
        }
        if (ParticipantFlagType::TYPE_T_SHIRT === $flagType->getType()) {
            return self::getTShirtGroupName($flag);
        }

        return self::getFlagPriceGroup($flag);
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

    public static function getFlagPriceGroup(ParticipantFlag $flag): string
    {
        if ($flag->getPrice() > 0) {
            return '💰 S příplatkem';
        }
        if (empty($flag->getPrice())) {
            return '🆓 Bez příplatku';
        }

        return '⬤ Ostatní';
    }

    public static function addParticipantNotesFields(FormBuilderInterface $builder): void
    {
        $builder->add(
            'participantNotes',
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
        return 'calendar_participant';
    }
}
