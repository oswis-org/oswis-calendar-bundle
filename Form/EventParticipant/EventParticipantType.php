<?php
/**
 * @noinspection UnknownInspectionInspection
 * @noinspection HtmlUnknownTarget
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\EventParticipant;

use OswisOrg\OswisAddressBookBundle\Form\StudentPersonType;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType as EventParticipantTypeEntity;
use OswisOrg\OswisCalendarBundle\Service\EventParticipantService;
use OswisOrg\OswisCalendarBundle\Service\EventService;
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

class EventParticipantType extends AbstractType
{
    protected EventService $eventService;

    protected EventParticipantService $participantService;

    public function __construct(EventService $eventService)
    {
        $this->eventService = $eventService;
        $this->participantService = $this->eventService->getEventParticipantService();
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
        if (!($participant instanceof EventParticipant)) {
            throw new PriceInvalidArgumentException('[účastník neexistuje]');
        }
        $participantType = $participant->getParticipantType();
        $event = $participant->getEvent();
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

    public function addEventField(FormBuilderInterface $builder, Event $event, EventParticipantTypeEntity $participantType): void
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
                'choice_attr'  => fn() => ['readonly' => 'readonly'],
                'choice_label' => fn(Event $e, $key, $value) => $this->getEventLabel($e, $participantType, $this->eventService),
            ]
        );
    }

    public function getEventLabel(Event $event, EventParticipantTypeEntity $participantType, EventService $eventService): string
    {
        $label = $event->getName().' ['.$event->getRangeAsText().']';
        $price = $event->getPrice($participantType);
        $label .= null !== $price ? ' ['.$price.',- Kč]' : '';
        $label .= !$event->isRegistrationsAllowed($participantType) ? ' [přihlášky nejsou povoleny]' : null;
        $label .= $eventService->getRemainingCapacity($event, $participantType) === 0 ? ' [překročena kapacita]' : null;

        return $label;
    }

    public static function addParticipantTypeField(FormBuilderInterface $builder, Event $event, EventParticipantTypeEntity $participantType): void
    {
        $builder->add(
            'participantType',
            EntityType::class,
            [
                'class'        => EventParticipantTypeEntity::class,
                'label'        => 'Typ účastníka',
                'required'     => true,
                'choices'      => $event->getParticipantTypes()->filter(fn(EventParticipantTypeEntity $type) => $type->isPublicOnWeb()),
                'data'         => $participantType,
                'choice_attr'  => fn() => ['readonly' => 'readonly'],
                'choice_label' => fn(EventParticipantTypeEntity $type, $key, $value) => $type->getName(),
            ]
        );
    }

    public static function addContactField(FormBuilderInterface $builder): void
    {
        $builder->add('contact', StudentPersonType::class, array('label' => 'Účastník', 'required' => true));
    }

    public function addFlagTypeFields(FormBuilderInterface $builder, Event $event, EventParticipant $participant): void
    {
        foreach ($event->getAllowedFlagsAggregatedByType($participant->getParticipantType()) as $flagsOfType) {
            self::addFlagField($builder, $flagsOfType['flagType'], $flagsOfType['flags'], $this->eventService, $participant->getParticipantType(), $event);
        }
    }

    public static function addFlagField(
        FormBuilderInterface $builder,
        ?EventParticipantFlagType $flagType,
        array $flags,
        EventService $eventService,
        EventParticipantTypeEntity $participantType,
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
                'choice_label' => fn(EventParticipantFlag $flag, $key, $value) => self::getFlagNameWithPrice($flag),
                'choice_attr'  => fn(EventParticipantFlag $flag, $key, $value) => self::getFlagAttributes($flag, $participantType, $eventService, $event),
                'group_by'     => fn(EventParticipantFlag $flag, $key, $value) => self::getFlagGroupName($flagType, $flag),
            ]
        );
    }

    /**
     * Get flag name and include (only non-zero) price.
     *
     * @param EventParticipantFlag $flag
     *
     * @return string
     */
    public static function getFlagNameWithPrice(EventParticipantFlag $flag): string
    {
        $price = ' ['.($flag->getPrice() > 0 ? '+' : '').$flag->getPrice().',- Kč]';

        return $flag->getName().(0 !== $flag->getPrice() ? $price : '');
    }

    public static function getFlagAttributes(
        EventParticipantFlag $flag,
        EventParticipantTypeEntity $participantType,
        EventService $eventService,
        Event $event
    ): array {
        $attributes = [];
        if (self::isFlagDisabled($flag, $participantType, $eventService, $event)) {
            $attributes['disabled'] = 'disabled';
        }

        return $attributes;
    }

    public static function isFlagDisabled(
        EventParticipantFlag $flag,
        EventParticipantTypeEntity $participantType,
        EventService $eventService,
        Event $event
    ): bool {
        return $eventService->getParticipantFlagRemainingCapacity($event, $flag, $participantType) === 0;
    }

    public static function getFlagGroupName(?EventParticipantFlagType $flagType, EventParticipantFlag $flag): ?string
    {
        if (null === $flagType) {
            return null;
        }
        if (EventParticipantFlagType::TYPE_T_SHIRT === $flagType->getType()) {
            return self::getTShirtGroupName($flag);
        }

        return self::getFlagPriceGroup($flag);
    }

    public static function getTShirtGroupName(EventParticipantFlag $flag): string
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

    public static function getFlagPriceGroup(EventParticipantFlag $flag): string
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
                'entry_type'    => EventParticipantNoteType::class,
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
                'data_class' => EventParticipant::class,
            )
        );
    }

    public function getName(): string
    {
        return 'calendar_event_participant';
    }
}
