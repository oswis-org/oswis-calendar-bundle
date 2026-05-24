<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailCategory;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ParticipantMailGroupEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'    => 'Název',
                'required' => false,
            ])
            ->add('twigTemplate', EntityType::class, [
                'label'        => 'Twig šablona',
                'class'        => TwigTemplate::class,
                'choice_label' => static fn (TwigTemplate $t): string => sprintf('%s (%s)', $t->getName() ?? '?', $t->getRegularTemplateName() ?? '—'),
                'required'     => false,
                'placeholder'  => '(žádná)',
            ])
            ->add('category', EntityType::class, [
                'label'        => 'Kategorie',
                'class'        => ParticipantMailCategory::class,
                'choice_label' => static fn (ParticipantMailCategory $c): string => sprintf('%s (%s)', $c->getName() ?? '?', $c->getType() ?? '—'),
                'required'     => false,
                'placeholder'  => '(žádná)',
            ])
            ->add('event', EntityType::class, [
                'label'        => 'Událost',
                'class'        => Event::class,
                'choice_label' => static fn (Event $e): string => sprintf('%s (%s)', $e->getShortName() ?? $e->getName() ?? '?', $e->getSlug() ?? '—'),
                'required'     => false,
                'placeholder'  => '(žádná — globální)',
            ])
            ->add('automaticMailing', CheckboxType::class, [
                'label'    => 'Automatické rozesílání',
                'required' => false,
            ])
            ->add('onlyActive', CheckboxType::class, [
                'label'    => 'Pouze aktivní účastníci',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Uložit',
                'attr'  => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ParticipantMailGroup::class]);
    }
}
