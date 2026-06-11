<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Export\ParticipantExportDefinition;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Enum\ExportFormat;
use OswisOrg\OswisCoreBundle\Export\ExportManager;
use OswisOrg\OswisCoreBundle\Export\ExportRequest;
use OswisOrg\OswisCoreBundle\Export\ExportResponseFactory;
use OswisOrg\OswisCoreBundle\Utils\StringUtils;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fáze G — hromadné akce nad přihláškami vybranými ve sjednoceném web-admin seznamu.
 *
 * Inkrement 1: BEZPEČNÉ operace bez side-effectu na reálné lidi (žádný e-mail):
 *  - {@see delete()} hromadné soft-delete (vratné přes „Obnovit"),
 *  - {@see export()} export vybraných (CSV/PDF přes sdílený ExportManager).
 * Výběr přichází jako `ids[]` z JS bulk baru v seznamu.
 *
 * Bezpečnost: ROLE_ADMIN, CSRF per akce, cap (smazání 100 jako reassign wizard, export 1000),
 * per-účastník flash log, návrat do původního filtrovaného seznamu (same-origin guard).
 * Hromadný e-mail + composer jsou záměrně Inkrement 2 (vlastní pojistky).
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminBulkParticipantsController extends AbstractController
{
    private const int DELETE_CAP = 100;
    private const int EXPORT_CAP = ParticipantExportDefinition::MAX_EXPORT_ROWS;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantService $participantService,
        private readonly ExportManager $exportManager,
        private readonly ExportResponseFactory $exportResponseFactory,
        private readonly ParticipantExportDefinition $participantExportDefinition,
    ) {
    }

    /**
     * Hromadné soft-delete vybraných přihlášek (vratné přes „Obnovit").
     */
    public function delete(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $return = $this->safeReturn($request);
        $ids = $this->readIds($request);
        if ([] === $ids) {
            $this->addFlash('warning', 'Nebyla vybrána žádná přihláška.');

            return new RedirectResponse($return);
        }
        if (count($ids) > self::DELETE_CAP) {
            $this->addFlash('error', sprintf(
                'Najednou lze smazat nejvýše %d přihlášek (vybráno %d). Rozděl do menších dávek.',
                self::DELETE_CAP,
                count($ids),
            ));

            return new RedirectResponse($return);
        }

        $deleted = [];
        $failed = [];
        foreach ($ids as $id) {
            $participant = $this->em->find(Participant::class, $id);
            if (!$participant instanceof Participant) {
                $failed[] = "#$id (nenalezen)";
                continue;
            }
            if (null !== $participant->getDeletedAt()) {
                continue; // už smazaný — tiše přeskočit
            }
            try {
                $this->participantService->delete($participant);
                $deleted[] = '#'.$id;
            } catch (\Throwable $e) {
                $failed[] = sprintf('#%d (%s)', $id, $e->getMessage());
            }
        }
        if ([] !== $deleted) {
            $this->addFlash('success', sprintf(
                'Smazáno %d přihlášek (lze obnovit): %s',
                count($deleted),
                implode(', ', $deleted),
            ));
        }
        if ([] !== $failed) {
            $this->addFlash('warning', sprintf('Nesmazáno %d: %s', count($failed), implode(', ', $failed)));
        }

        return new RedirectResponse($return);
    }

    /**
     * Export vybraných přihlášek (CSV/PDF) — sdílená ExportManager pipeline, jen filtr na vybraná ID.
     */
    public function export(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_export', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $ids = $this->readIds($request);
        if ([] === $ids) {
            $this->addFlash('warning', 'Nebyla vybrána žádná přihláška.');

            return new RedirectResponse($this->safeReturn($request));
        }
        if (count($ids) > self::EXPORT_CAP) {
            $this->addFlash('error', sprintf(
                'Export je omezen na %d záznamů (vybráno %d). Zužte výběr.',
                self::EXPORT_CAP,
                count($ids),
            ));

            return new RedirectResponse($this->safeReturn($request));
        }

        $participants = $this->participantService->getRepository()->findBy(['id' => $ids]);
        usort(
            $participants,
            static fn (Participant $a, Participant $b): int => StringUtils::compareCzech($a->getSortableName(), $b->getSortableName()),
        );
        $exportRequest = new ExportRequest(
            ExportFormat::fromRequest($request->request->getString('format')),
            null,
            sprintf('Vybrané přihlášky (%d)', count($participants)),
        );

        return $this->exportResponseFactory->toResponse(
            $this->exportManager->render(
                $this->participantExportDefinition,
                new ArrayCollection($participants),
                $exportRequest,
            ),
        );
    }

    /**
     * Vybraná ID z POST `ids[]` → deduplikovaný seznam kladných intů.
     *
     * @return list<int>
     */
    private function readIds(Request $request): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $request->request->all('ids'),
        ))));
    }

    /**
     * Návratová URL z POST `return`; jen same-origin relativní cesta (žádný open-redirect).
     */
    private function safeReturn(Request $request): string
    {
        $return = $request->request->getString('return');
        if ('' !== $return && str_starts_with($return, '/') && !str_starts_with($return, '//')) {
            return $return;
        }

        return $this->generateUrl('oswis_org_oswis_calendar_web_admin_participants_list');
    }
}
