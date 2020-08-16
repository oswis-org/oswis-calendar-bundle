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
        $builder->add('textValue', TextareaType::class, []);
        $builder->add('type', ChoiceType::class, ["choices" => fn() => ParticipantPaymentsImport::getAllowedTypes()]);
        $builder->add(
            'save',
            SubmitType::class,
            [
                'label' => 'Importovat platby',
                'attr'  => ['class' => 'btn-lg btn-primary btn-block font-weight-bold text-uppercase'],
            ]
        );
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
