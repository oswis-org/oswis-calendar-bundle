<?php
/**
 * @noinspection UnknownInspectionInspection
 * @noinspection HtmlUnknownTarget
 * @noinspection MethodShouldBeFinalInspection
 */

namespace Zakjakub\OswisCalendarBundle\Form;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Zakjakub\OswisAddressBookBundle\Form\StudentPersonType;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantService;
use Zakjakub\OswisCalendarBundle\Service\EventSeriesService;
use Zakjakub\OswisCalendarBundle\Service\EventService;
use Zakjakub\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;

class EventParticipantType extends AbstractType
{
    protected EventService $eventService;

    protected EventSeriesService $eventSeriesService;

    protected EventParticipantService $participantService;

    public function __construct(EventService $eventService, EventSeriesService $eventSeriesService)
    {
        $this->eventService = $eventService;
        $this->eventSeriesService = $eventSeriesService;
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
        $eventService = $this->eventService;
        $participant = $builder->getData();
        if (!($participant instanceof EventParticipant)) {
            throw new PriceInvalidArgumentException('účastník neexistuje');
        }
        $participantType = $participant->getEventParticipantType();
        $event = $participant->getEvent();
        if (null === $participantType || null === $event) {
            throw new PriceInvalidArgumentException('událost, typ účastníka');
        }
        $builder->add(
            'event',
            EntityType::class,
            array(
                'class'        => Event::class,
                'label'        => 'Akce',
                'required'     => true,
                'choices'      => [$event],
                'data'         => $event,
                'choice_label' => fn(Event $e, $key, $value) => $this->getEventLabel($e, $participantType, $eventService),
            )
        )->add(
            'contact',
            StudentPersonType::class,
            array('label' => 'Účastník', 'required' => true)
        );
        $this->addFlagsFields($builder, $event, $participant);
        $builder->add(
            'eventParticipantNotes',
            CollectionType::class,
            array(
                'label'         => false,
                'entry_type'    => EventParticipantNoteType::class,
                'entry_options' => array(
                    'label' => false,
                ),
            )
        );
        $this->addGdprField($builder);
        $builder->add(
            'save',
            SubmitType::class,
            array(
                'label' => 'Registrovat se!',
                'attr'  => ['class' => 'btn-lg btn-primary btn-block'],
            )
        );
    }

    public function getEventLabel(
        Event $e,
        \Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType $participantType,
        EventService $eventService
    ): string {
        $label = $e->getName().' '.$e->getRangeAsText();
        $price = $e->getPrice($participantType);
        $label .= null !== $price ? ' ('.$price.',- Kč)' : '';
        $label .= !$e->isRegistrationsAllowed($participantType) ? ' (přihlášky nejsou povoleny)' : null;
        $label .= $eventService->getRemainingCapacity($e, $participantType) < 1 ? ' (překročena kapacita)' : null;

        return $label;
    }

    public function addFlagsFields(FormBuilderInterface $builder, Event $event, EventParticipant $participant): void
    {
        $participantType = $participant->getEventParticipantType();
        $flagsRows = $event->getAllowedFlagsAggregatedByType($participantType);
        foreach ($flagsRows as $flagsRow) {
            $flagType = $flagsRow['flagType'];
            assert($flagType instanceof EventParticipantFlagType);
            $flags = $flagsRow['flags'];
            $builder->add(
                'flag_'.$flagType->getSlug(),
                ChoiceType::class,
                array(
                    'label'        => $flagType->getName(),
                    'help'         => $flagType->getDescription(),
                    'required'     => $flagType->getMinInEventParticipant() > 1,
                    'choices'      => $flags,
                    'mapped'       => false,
                    'expanded'     => false,
                    'multiple'     => false,
                    'choice_label' => fn(EventParticipantFlag $flag, $key, $value) => $this->getFlagNameWithPrice($flag),
                    'group_by'     => static function () use ($flagType) {
                        if (EventParticipantFlagType::TYPE_T_SHIRT === $flagType->getType()) {
                            return fn(EventParticipantFlag $flag, $key, $value) => self::getTShirtGroupName($flag);
                        }

                        return fn(EventParticipantFlag $flag, $key, $value) => self::getFlagPriceGroup($flag);
                    },
                )
            );
        }
    }

    public function getFlagNameWithPrice(EventParticipantFlag $flag): string
    {
        $label = $flag->getName() ?? '';
        $label .= $flag->getPrice() < 0 ? ' ('.$flag->getPrice().',- Kč)' : '';
        $label .= $flag->getPrice() > 0 ? ' (+'.$flag->getPrice().',- Kč)' : '';

        return $label;
    }

    public function getTShirtGroupName(EventParticipantFlag $flag): string
    {
        if (strpos($flag->getName(), 'Pán') !== false) {
            return 'Pánské tričko';
        }
        if (strpos($flag->getName(), 'Dám') !== false) {
            return 'Dámské tričko';
        }

        return 'Ostatní';
    }

    public function getFlagPriceGroup(EventParticipantFlag $flag): string
    {
        if ($flag->getPrice() > 0) {
            return 'S příplatkem';
        }
        if (empty($flag->getPrice())) {
            return 'Bez příplatku';
        }

        return 'Ostatní';
    }

    public function addGdprField(FormBuilderInterface $builder): void
    {
        $builder->add(
            'agreeGDPR',
            CheckboxType::class,
            array(
                'mapped'     => false,
                'label'      => 'Uvedením údajů potvrzuji souhlas s evidencí těchto dat.',
                'help'       => "Přečetl(a) jsem si a souhlasím s 
                                <a href='/gdpr' target='_blank'>podmínkami zpracování osobních údajů</a>.",
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
        return 'event_participant';
    }

}
