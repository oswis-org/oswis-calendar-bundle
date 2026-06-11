<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Form\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailCategory;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantFilterEvaluator;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
                'choice_label' => static fn (Event $e): string => sprintf('%s (%s)', $e->getShortName() ?? $e->getName() ?? '?', $e->getSlug()),
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
            ->add('filterExpression', TextareaType::class, [
                'label'    => 'Filtr příjemců (volitelný)',
                'required' => false,
                'attr'     => [
                    'rows'        => 2,
                    'maxlength'   => ParticipantFilterEvaluator::MAX_EXPRESSION_LENGTH,
                    'placeholder' => "hasFlagInCategory('fakulta') and isPaid()",
                    'style'       => 'font-family: monospace;',
                ],
                'help'     => 'Stejný jazyk jako pokročilý filtr v seznamu přihlášek: hasFlag(\'slug\'), '
                    .'hasFlagInCategory(\'slug-kategorie\'), hasFlagOfType(\'typ\'), isPaid(), isDeleted(), '
                    .'eventSlug(), gender() — kombinace přes and / or / not. Prázdné = bez omezení. '
                    .'POZOR: filtr platí i pro systémové typy (summary/payment) — účastník mimo filtr pak '
                    .'spadne do další skupiny dle priority; bez záchytné skupiny bez filtru e-mail nedostane.',
                // Syntax-validate on save: a broken stored expression would fail-closed at send time
                // (nobody mailed) — reject it here instead. The evaluator is stateless and dependency-free.
                'constraints' => [
                    new Callback(static function (?string $value, ExecutionContextInterface $context): void {
                        $error = (new ParticipantFilterEvaluator())->validate($value);
                        if (null !== $error) {
                            $context->buildViolation('Neplatný filtrační výraz: '.$error)->addViolation();
                        }
                    }),
                ],
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
