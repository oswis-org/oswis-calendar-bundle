<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Participant\StaffTeam;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Tým / podtým organizačního týmu (spec 2026-06-12: StaffTeam per turnus, členství M2M). Přiřazuje se
 * k aktivitám místo vypisování všech členů; itinerář jednotlivce se pak expanduje. Členové se spravují
 * zvlášť (add/remove) — tady jen název.
 */
final class StaffTeamEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'    => 'Název týmu',
                'required' => false,
                'help'     => 'Např. „Kuchyň", „Technika", „Hlavní tým".',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StaffTeam::class,
        ]);
    }
}
