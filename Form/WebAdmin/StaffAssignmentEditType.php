<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffAssignment;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffRole;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulář jedné SLUŽBY v rozpisu ({@see StaffAssignment} s `activity = null`).
 *
 * Obsazení je BUĎ interní člen týmu, NEBO externí jméno — entita si to hlídá sama
 * (`validateHasAssignee`), takže obě pole jsou nepovinná a validace je jedna, sdílená s API.
 * Čas je ABSOLUTNÍ (u služeb; relativní režim vůči aktivitě se tu needituje) a konec smí být
 * „dřív" než začátek — to je legitimní směna přes půlnoc (noční hlídka, řízení 6–6).
 */
final class StaffAssignmentEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<StaffRole> $roles */
        $roles = $options['roles'];
        /** @var list<Participant> $staffPool */
        $staffPool = $options['staff_pool'];

        $builder
            ->add('role', EntityType::class, [
                'label'        => 'Funkce',
                'class'        => StaffRole::class,
                'choices'      => $roles,
                'choice_label' => static fn (StaffRole $r): string => $r->getName() ?? ('#'.$r->getId()),
                'required'     => true,
                'placeholder'  => '— vyber funkci —',
            ])
            ->add('participant', EntityType::class, [
                'label'        => 'Člen týmu',
                'class'        => Participant::class,
                'choices'      => $staffPool,
                'choice_label' => static fn (Participant $p): string => $p->getContactForRead()?->getName() ?? ('#'.$p->getId()),
                'required'     => false,
                'placeholder'  => '— externí (vyplň jméno níže) —',
                'help'         => [] === $staffPool
                    ? 'Tým zatím není zaregistrovaný — obsazuj externím jménem.'
                    : 'Buď člen týmu, nebo externí jméno (ne obojí).',
            ])
            ->add('externalName', TextType::class, [
                'label'    => 'Externí jméno',
                'required' => false,
            ])
            ->add('startDateTime', DateTimeType::class, [
                'label'    => 'Začátek',
                'widget'   => 'single_text',
                'required' => false,
            ])
            ->add('endDateTime', DateTimeType::class, [
                'label'    => 'Konec',
                'widget'   => 'single_text',
                'required' => false,
                'help'     => 'Konec smí být dřív než začátek — pak jde o směnu přes půlnoc.',
            ])
            ->add('note', TextareaType::class, [
                'label'    => 'Poznámka',
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StaffAssignment::class,
            'roles'      => [],
            'staff_pool' => [],
        ]);
        $resolver->setAllowedTypes('roles', 'array');
        $resolver->setAllowedTypes('staff_pool', 'array');
    }
}
