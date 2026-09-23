<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCoreBundle\Entity\AppUserMail\AppUserMailGroup;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;

/**
 * Předmět, jaký šablona dostávala do 23. 9. 2026 (než měla vlastní pole „Předmět"), zapsaný jako Twig.
 *
 * Formulář šablony jím předvyplní prázdné pole a příkaz `app:mail:verify-subjects` jím na produkčních
 * datech ověří, že předvyplněný předmět vyjde stejně jako dosavadní. Pravidla odpovídají
 * {@see ParticipantMailService}: název šablony + „ – akce"; platba „Přijetí platby" / „Vrácení/oprava
 * platby"; smazaná přihláška u shrnutí „Shrnutí smazané přihlášky"; maily k účtu jen název (akci nemají).
 */
final readonly class DosavadniPredmetSablony
{
    public const string SMAZANA = 'Shrnutí smazané přihlášky';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** Twig předmětu k předvyplnění, nebo null (bloky a stránky předmět nemají). */
    public function twig(TwigTemplate $template): ?string
    {
        if (!in_array($template->getKind(), [TwigTemplate::KIND_CAMPAIGN, TwigTemplate::KIND_SYSTEM], true)) {
            return null;
        }
        $nazev = $template->getName() ?? 'Informace k akci';
        $druhy = $this->druhy($template);
        if ([] === $druhy && $this->jeUctova($template)) {
            return $nazev;
        }
        if (in_array(ParticipantMail::TYPE_PAYMENT, $druhy, true)) {
            return '{% if payment.numericValue|default(0) < 0 %}Vrácení/oprava platby{% else %}Přijetí platby{% endif %} – {{ akce }}';
        }
        if (in_array(ParticipantMail::TYPE_SUMMARY, $druhy, true)) {
            return '{% if participant.deletedAt %}'.self::SMAZANA.'{% else %}'.$nazev.'{% endif %} – {{ akce }}';
        }

        return $nazev.' – {{ akce }}';
    }

    /**
     * Druhy mailů k přihláškám (typ kategorie), pro které šablonu používají mailové skupiny.
     *
     * @return list<string>
     */
    public function druhy(TwigTemplate $template): array
    {
        $druhy = [];
        foreach ($this->em->getRepository(ParticipantMailGroup::class)->findBy(['twigTemplate' => $template]) as $skupina) {
            if (null !== ($druh = $skupina->getCategory()?->getType())) {
                $druhy[] = $druh;
            }
        }

        return array_values(array_unique($druhy));
    }

    /** Používá šablonu řetězec mailů k uživatelskému účtu? */
    public function jeUctova(TwigTemplate $template): bool
    {
        return [] !== $this->em->getRepository(AppUserMailGroup::class)->findBy(['twigTemplate' => $template]);
    }
}
