<?php
/**
 * @noinspection UnknownInspectionInspection
 * @noinspection HtmlUnknownTarget
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use OswisOrg\OswisAddressBookBundle\Form\StudentPersonType;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegRange;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipantFormType extends AbstractType
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
        if (!($participant instanceof Participant) || !(($range = $participant->getRegRange()) instanceof RegRange)) {
            throw new PriceInvalidArgumentException('[nepodařilo se vytvořit účastníka]');
        }
        $participantType = $range->getParticipantCategory();
        $event = $range->getEvent();
        if (null === $participantType || null === $event) {
            $message = null === $participantType ? '[typ účastníka nenastaven]' : '';
            $message .= null !== $event ? '[událost nenastavena]' : '';
            throw new PriceInvalidArgumentException($message);
        }
        self::addContactField($builder);
        $this->addFlagCategoryFields($builder, $participant);
        self::addParticipantNotesFields($builder);
        self::addSubmitButton($builder);
    }

    public static function addContactField(FormBuilderInterface $builder): void
    {
        $builder->add('contact', StudentPersonType::class, array('label' => 'Účastník', 'required' => true));
    }

    public function addFlagCategoryFields(FormBuilderInterface $builder, Participant $participant): void
    {
        $builder->add(
            'flagGroups',
            CollectionType::class,
            array(
                'label'         => false,
                'entry_type'    => ParticipantFlagGroupType::class,
                'entry_options' => [
                    'participant' => $participant,
                ],
            )
        );
    }

    public static function addParticipantNotesFields(FormBuilderInterface $builder): void
    {
        // TODO: PRE_SUBMIT => Remove empty notes.
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

    public static function addSubmitButton(FormBuilderInterface $builder): void
    {
        $builder->add('save', SubmitType::class, ['label' => 'Přihlásit se!', 'attr' => ['class' => 'btn-lg btn-primary btn-block'],]);
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