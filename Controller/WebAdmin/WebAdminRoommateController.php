<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Accommodation\RoommatePreference;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Accommodation\RoommatePreferenceService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Zápisová cesta pro preference spolubydlení — to, co docblock entity sliboval („ZÁPIS přes web
 * admin / službu") a co do 2026-08-15 neexistovalo: {@see RoommatePreference} šla naplnit jen
 * ručně v DB, takže modul byl fakticky nepoužitelný, přestože ho spec vede v rozsahu v1.
 *
 * Formuláře se renderují rovnou na detailu účastníka (stejně jako poznámky), protože požadavek
 * na spolubydlení chodí v kontextu konkrétního člověka — e-mailem nebo v poznámce z přihlášky.
 *
 * Párování a detekci konfliktů řeší {@see RoommatePreferenceService}, ne tenhle controller.
 */
#[IsGranted('ROLE_MANAGER')]
final class WebAdminRoommateController extends AbstractController
{
    public function __construct(
        private readonly RoommatePreferenceService $roommateService,
        private readonly ParticipantService $participantService,
    ) {
    }

    public function create(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('roommate_create_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->loadParticipant($participantId);

        $text = trim((string) $request->request->get('preferenceText'));
        if ('' === $text) {
            $this->addFlash('error', 'Napiš, s kým chce účastník bydlet.');

            return $this->redirectToDetail($participantId);
        }

        $source = (string) $request->request->get('source', RoommatePreference::SOURCE_EMAIL);
        if (!in_array($source, self::allowedSources(), true)) {
            $source = RoommatePreference::SOURCE_EMAIL;
        }

        // Volitelné ruční určení druhé osoby — použije se, když je jméno víceznačné nebo
        // napsané tak, že se automaticky nespáruje.
        $withParticipant = null;
        $withId = $request->request->getInt('withParticipantId');
        if ($withId > 0) {
            $withParticipant = $this->participantService->getParticipant([
                ParticipantRepository::CRITERIA_ID => $withId,
            ]);
            if (!$withParticipant instanceof Participant) {
                $this->addFlash('error', sprintf('Přihláška #%d neexistuje — požadavek uložen bez ní.', $withId));
            }
        }

        $preference = $this->roommateService->record($participant, $text, $source, $withParticipant);
        $this->addFlash('success', self::describeResult($preference));

        return $this->redirectToDetail($participantId);
    }

    public function delete(Request $request, int $participantId, int $preferenceId): Response
    {
        if (!$this->isCsrfTokenValid('roommate_delete_'.$preferenceId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $preference = $this->roommateService->find($preferenceId)
            ?? throw $this->createNotFoundException('Požadavek na spolubydlení nenalezen.');
        // Mazat smí obě strany vazby — požadavek se zobrazuje na detailu obou lidí.
        $belongsToParticipant = $preference->getParticipant()?->getId() === $participantId
            || $preference->getWithParticipant()?->getId() === $participantId;
        if (!$belongsToParticipant) {
            throw $this->createAccessDeniedException('Požadavek nepatří tomuto účastníkovi.');
        }

        $this->roommateService->remove($preference);
        $this->addFlash('success', 'Požadavek na spolubydlení smazán.');

        return $this->redirectToDetail($participantId);
    }

    /**
     * Přepáruje všechny nespárované požadavky. Smysl: lidé se hlásí průběžně, takže jméno,
     * které při zadání nesedělo na nikoho, může být později spárovatelné.
     */
    public function rematch(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('roommate_rematch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $stats = $this->roommateService->resolveUnmatched();
        $this->addFlash('success', sprintf(
            'Přepárováno: %d spárováno, %d v konfliktu, %d stále bez shody.',
            $stats['resolved'],
            $stats['conflict'],
            $stats['stillUnmatched'],
        ));

        return new RedirectResponse(
            $request->headers->get('referer')
            ?? $this->generateUrl('oswis_org_oswis_calendar_web_admin_participants_list'),
        );
    }

    /** @return list<string> */
    private static function allowedSources(): array
    {
        return [
            RoommatePreference::SOURCE_REGISTRATION,
            RoommatePreference::SOURCE_EMAIL,
            RoommatePreference::SOURCE_OTHER,
        ];
    }

    /**
     * Hláška říká, co se STALO, ne jen že se uložilo — stav párování je to podstatné a bez něj
     * by tým netušil, že požadavek nikoho nenašel a u příjezdu nezafunguje.
     */
    private static function describeResult(RoommatePreference $preference): string
    {
        $with = $preference->getWithParticipant();

        return match ($preference->getStatus()) {
            RoommatePreference::STATUS_OK => sprintf(
                'Spolubydlení uloženo a spárováno s „%s" (#%d).',
                (string) $with?->getContact()?->getName(),
                (int) $with?->getId(),
            ),
            RoommatePreference::STATUS_CONFLICT => 'Spolubydlení uloženo, ale je v KONFLIKTU — '
                .'buď je ten člověk na jiném turnusu, nebo jméno sedí na víc lidí. Vyber přihlášku ručně.',
            default => 'Spolubydlení uloženo, ale nikoho takového jsem nenašel. '
                .'Až se přihlásí, pomůže tlačítko „Přepárovat".',
        };
    }

    private function loadParticipant(int $participantId): Participant
    {
        return $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');
    }

    private function redirectToDetail(int $participantId): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId, '_fragment' => 'spolubydleni'],
        ));
    }
}
