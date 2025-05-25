<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Form\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPaymentsImport;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Exception\AccessException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipantPaymentsImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('textValue', TextareaType::class, [
            "label" => 'Seznam plateb ve formátu CSV',
            "help" => "Zadejte seznam plateb ve formě exportu z internetového bankovnictví. 
                U každé platby by měl být obsažen externí identifikátor, jinak při opětovném importu dojde k vytvoření duplicity.",
            "required" => false,
        ]);
        $builder->add('type', ChoiceType::class, [
            "label" => "Formát importu",
            "choices" => ParticipantPaymentsImport::getAllowedTypes(),
            "choice_label" => fn ($choice, $key, $value) => $value,
        ]);
        $builder->add('settingsCode', ChoiceType::class, [
            "label" => "Nastavení formátu importu",
            "choices" => ParticipantPaymentsImport::SETTINGS_CODES,
            "choice_label" => fn ($choice, $key, $value) => $value,
        ]);
        $builder->add('save', SubmitType::class, [
            'label' => 'Importovat platby',
            'attr' => ['class' => 'btn-lg btn-primary btn-block font-weight-bold text-uppercase'],
        ]);
    }

    /**
     * @param OptionsResolver $resolver
     *
     * @throws AccessException
     */
    final public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ParticipantPaymentsImport::class]);
    }

    public function getName(): string
    {
        return 'calendar_participant_payments_import';
    }
}
