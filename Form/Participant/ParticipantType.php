<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use OswisOrg\OswisAddressBookBundle\Form\PersonType;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipantType extends AbstractType
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    /**
     * @param  FormBuilderInterface  $builder
     * @param  array  $options
     *
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException
     */
    final public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $participant = $builder->getData();
        if (!($participant instanceof Participant)) {
            throw new PriceInvalidArgumentException('[nepodařilo se vytvořit účastníka]');
        }
        $this->logger->info("Form data:");
        $this->logger->info(null !== $participant->getContact() ? 'Contact OK.' : 'Without contact!');
        if (!(($range = $participant->getOffer(false)) instanceof RegistrationOffer)) {
            throw new PriceInvalidArgumentException('[špatný rozsah přihlášek]');
        }
        $participantType = $range->getParticipantCategory();
        $event = $range->getEvent();
        if (null === $participantType || null === $event) {
            $message = null === $participantType ? '[typ účastníka nenastaven]' : '';
            $message .= null !== $event ? '[událost nenastavena]' : '';
            throw new PriceInvalidArgumentException($message);
        }
        self::addContactField($builder, null !== $participant->getContact()?->getId());
        $this->addParticipantFlagGroupFields($builder, $participant);
        self::addParticipantNotesFields($builder);
        self::addSubmitButton($builder);
    }

    public static function addContactField(FormBuilderInterface $builder, bool $existing = false): void
    {
        $builder->add('contact', PersonType::class, [
            'label'    => 'Účastník',
            'required' => true,
            'disabled' => $existing,
        ]);
    }

    public function addParticipantFlagGroupFields(FormBuilderInterface $builder, Participant $participant): void
    {
        $builder->add('flagGroups', CollectionType::class, [
            'label'              => false,
            'entry_type'         => FlagGroupOfParticipantType::class,
            'mapped'             => true,
            'allow_extra_fields' => true,
            'entry_options'      => [
                'label'       => false,
                'participant' => $participant,
            ],
        ]);
    }

    public static function addParticipantNotesFields(FormBuilderInterface $builder): void
    {
        // TODO: PRE_SUBMIT => Remove empty notes.
        $builder->add('notes', CollectionType::class, [
            'label'         => false,
            'entry_type'    => ParticipantNoteFormType::class,
            'entry_options' => [
                'label' => false,
            ],
        ]);
    }

    public static function addSubmitButton(FormBuilderInterface $builder): void
    {
        $builder->add('save', SubmitType::class, [
            'label' => 'Přihlásit se',
            'attr'  => ['class' => 'btn-lg btn-primary btn-block font-weight-bold text-uppercase'],
        ]);
    }

    /**
     * @param  OptionsResolver  $resolver
     *
     * @throws AccessException
     */
    final public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Participant::class,
        ]);
    }

    public function getName(): string
    {
        return 'calendar_participant';
    }
}
