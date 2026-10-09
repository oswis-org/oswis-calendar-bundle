<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplateVersion;
use OswisOrg\OswisCoreBundle\Repository\TwigTemplateRepository;
use OswisOrg\OswisCoreBundle\Utils\RadkovyRozdil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Historie verzí šablony (dávka 4, spec §6.3): seznam verzí, verze s rozdílem proti předchozí a obnovení. Obnovení
 * = nové uložení obsahu starší verze (vznikne nová verze s poznámkou), historie se nepřepisuje. Obnovuje se přes
 * zámek revize jako běžné uložení, takže nepřepíše souběžnou úpravu.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminTemplateHistoryController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function history(int $id): Response
    {
        $template = $this->sablona($id);

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/history.html.twig', [
            'template'   => $template,
            'versions'   => $this->em->getRepository(TwigTemplateVersion::class)->findBy(['template' => $id], ['number' => 'DESC']),
            'revize'     => $this->revize($id),
            'pageTitle'  => sprintf('Historie verzí: %s', $template->getName() ?? '#'.$id),
            'page_title' => sprintf('Historie verzí: %s :: ADMIN', $template->getName() ?? '#'.$id),
        ]);
    }

    public function version(int $id, int $number): Response
    {
        $template = $this->sablona($id);
        $verze = $this->verze($id, $number);
        $predchozi = $number > 1 ? $this->em->getRepository(TwigTemplateVersion::class)->findOneBy(['template' => $id, 'number' => $number - 1]) : null;
        $posledni = $this->em->getRepository(TwigTemplateVersion::class)->findOneBy(['template' => $id], ['number' => 'DESC']);

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/version.html.twig', [
            'template'   => $template,
            'version'    => $verze,
            'previous'   => $predchozi,
            'jePosledni' => $posledni?->getNumber() === $number,
            'revize'     => $this->revize($id),
            'zmenyPoli'  => self::zmenyPoli($predchozi, $verze),
            'rozdil'     => RadkovyRozdil::porovnat($predchozi?->getTextValue(), $verze->getTextValue()),
            'pageTitle'  => sprintf('Verze %d: %s', $number, $template->getName() ?? '#'.$id),
            'page_title' => sprintf('Verze %d: %s :: ADMIN', $number, $template->getName() ?? '#'.$id),
        ]);
    }

    public function restore(Request $request, int $id, int $number): Response
    {
        if (!$this->isCsrfTokenValid('template_restore_'.$id.'_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $template = $this->sablona($id);
        $verze = $this->verze($id, $number);
        $repository = $this->em->getRepository(TwigTemplate::class);
        \assert($repository instanceof TwigTemplateRepository);
        $nova = $repository->zamknoutRevizi($id, $request->request->getInt('revize', -1));
        if (null === $nova) {
            $this->addFlash('danger', 'Šablonu mezitím někdo uložil — obnovení se neprovedlo. Podívej se na aktuální stav a zkus to znovu.');

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_mail_template_history', ['id' => $id]);
        }
        $template->setName($verze->getName());
        $template->setSubject($verze->getSubject());
        $template->setTextValue($verze->getTextValue());
        $template->setRegularTemplateName($verze->getParent());
        $template->setKind($verze->getKind());
        $template->setForcedSlug($verze->getSlug());
        $template->setRevision($nova);
        $template->setPoznamkaKVerzi(sprintf('Obnoveno z verze %d', $number));
        $this->em->flush();
        $this->addFlash('success', sprintf('Šablona obnovena do stavu verze %d (uloženo jako nová verze).', $number));

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_mail_template_history', ['id' => $id]);
    }

    /**
     * Která pole (mimo text) se proti předchozí verzi změnila — text má vlastní řádkový rozdíl.
     *
     * @return list<array{pole: string, pred: ?string, po: ?string}>
     */
    private static function zmenyPoli(?TwigTemplateVersion $pred, TwigTemplateVersion $po): array
    {
        if (null === $pred) {
            return [];
        }
        $zmeny = [];
        foreach ([
            'Název'      => [$pred->getName(), $po->getName()],
            'Předmět'    => [$pred->getSubject(), $po->getSubject()],
            'Vychází z'  => [$pred->getParent(), $po->getParent()],
            'Druh'       => [$pred->getKind(), $po->getKind()],
            'Slug'       => [$pred->getSlug(), $po->getSlug()],
        ] as $pole => [$a, $b]) {
            if ($a !== $b) {
                $zmeny[] = ['pole' => $pole, 'pred' => $a, 'po' => $b];
            }
        }

        return $zmeny;
    }

    /** Revize z databáze — obnovení přes zámek revize nepřepíše souběžnou úpravu. */
    private function revize(int $id): int
    {
        $repository = $this->em->getRepository(TwigTemplate::class);
        \assert($repository instanceof TwigTemplateRepository);

        return $repository->revizeZDatabaze($id);
    }

    private function sablona(int $id): TwigTemplate
    {
        return $this->em->find(TwigTemplate::class, $id) ?? throw $this->createNotFoundException('Šablona nenalezena.');
    }

    private function verze(int $id, int $number): TwigTemplateVersion
    {
        return $this->em->getRepository(TwigTemplateVersion::class)->findOneBy(['template' => $id, 'number' => $number])
            ?? throw $this->createNotFoundException('Verze nenalezena.');
    }
}
