<?php
/**
 * @noinspection UnknownInspectionInspection
 * @noinspection HtmlUnknownTarget
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use OswisOrg\OswisAddressBookBundle\Form\StudentPersonType;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\RegistrationFlagCategory;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
        if (!($participant instanceof Participant) || !(($range = $participant->getRange()) instanceof RegistrationRange)) {
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
        $this->addFlagCategoryFields($builder, $participant);
        self::addParticipantNotesFields($builder);
        self::addGdprField($builder);
        self::addSubmitButton($builder);
    }

    public static function addContactField(FormBuilderInterface $builder): void
    {
        $builder->add('contact', StudentPersonType::class, array('label' => 'Účastník', 'required' => true));
    }

    public function addFlagCategoryFields(FormBuilderInterface $builder, Participant $participant): void
    {
        $builder->add(
            'flagCategories',
            CollectionType::class,
            array(
                'label'      => false,
                'entry_type' => ParticipantFlagCategoryType::class,
                'entry_options' => [
                    'participant' => $participant,
                ]
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

    public static function addGdprField(FormBuilderInterface $builder): void
    {
        // TODO: Refactor to flag.
        $builder->add(
            'agreeGDPR',
            CheckboxType::class,
            array(
                'mapped'     => false,
                'label'      => 'Uvedením údajů potvrzuji souhlas s evidencí těchto dat.',
                'help'       => "<strong>
                            Přečetl(a) jsem si a souhlasím s 
                            <a href='/gdpr' target='_blank'><i class='fas fa-user-secret'></i> podmínkami pro zpracování osobních údajů</a>.
                            </strong>",
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
        return 'calendar_participant_registration';
    }
}
