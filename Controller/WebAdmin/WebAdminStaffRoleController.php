<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffAssignment;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffRole;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\StaffRoleEditType;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Web admin CRUD číselníku FUNKCÍ týmu ({@see StaffRole}).
 *
 * Proč to musí existovat: funkce spotřebovává rozpis služeb (Ionic) i editor obsazení aktivit, ale
 * dosud je nešlo nikde založit — po nasazení by byl číselník PRÁZDNÝ a rozpis nepoužitelný, přičemž
 * jediná cesta by byl ruční SQL INSERT (uživatel přímé SQL zásahy odmítá, viz historie u mail configu).
 *
 * Součástí je i akce „založit výchozí sadu" — idempotentní pomůcka pro nové nasazení/ročník.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminStaffRoleController extends AbstractController
{
    /**
     * Výchozí sada funkcí pro nové nasazení: [název, strojový klíč, barva, rovina použití].
     * Vychází z reálného provozu (řízení, jídelna, recepce, zdravotník, svolávání, technika, úklid,
     * vrátnice = služby (rozpis); vedení aktivity + technika u aktivity = role u aktivit).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const array DEFAULT_ROLES = [
        ['Řízení', 'rizeni', '#d32f2f', StaffRole::APPLIES_SERVICE],
        ['Jídelna', 'jidelna', '#f57c00', StaffRole::APPLIES_SERVICE],
        ['Recepce', 'recepce', '#1976d2', StaffRole::APPLIES_SERVICE],
        ['Zdravotník', 'zdravotnik', '#388e3c', StaffRole::APPLIES_SERVICE],
        ['Svolávání', 'svolavani', '#5c6bc0', StaffRole::APPLIES_BOTH],
        ['Technika', 'technika', '#00897b', StaffRole::APPLIES_BOTH],
        ['Úklid', 'uklid', '#6d4c41', StaffRole::APPLIES_SERVICE],
        ['Vrátnice', 'vratnice', '#546e7a', StaffRole::APPLIES_SERVICE],
        ['Vede aktivitu', 'vede', '#7b1fa2', StaffRole::APPLIES_ACTIVITY],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function index(): Response
    {
        $roles = $this->em->getRepository(StaffRole::class)->findBy([], ['name' => 'ASC']);

        return $this->render('@OswisOrgOswisCalendar/web_admin/staff_role/index.html.twig', [
            'roles'      => $roles,
            'usage'      => $this->usageCounts(),
            'pageTitle'  => 'Funkce týmu',
            'page_title' => 'Funkce týmu :: ADMIN',
        ]);
    }

    public function new(Request $request): Response
    {
        $role = new StaffRole(null, null, null, StaffRole::APPLIES_SERVICE);
        $form = $this->createForm(StaffRoleEditType::class, $role);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $role->updateSlug();
            $this->em->persist($role);
            $this->em->flush();
            $this->addFlash('success', sprintf('Funkce „%s" založena.', $role->getName() ?? '#'.$role->getId()));

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_staff_roles');
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/staff_role/edit.html.twig', [
            'form'       => $form->createView(),
            'role'       => $role,
            'pageTitle'  => 'Nová funkce týmu',
            'page_title' => 'Nová funkce týmu :: ADMIN',
        ]);
    }

    public function edit(Request $request, int $id): Response
    {
        $role = $this->em->find(StaffRole::class, $id);
        if (!$role instanceof StaffRole) {
            throw $this->createNotFoundException('Funkce nenalezena.');
        }
        $form = $this->createForm(StaffRoleEditType::class, $role);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', sprintf('Funkce „%s" uložena.', $role->getName() ?? '#'.$id));

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_staff_roles');
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/staff_role/edit.html.twig', [
            'form'       => $form->createView(),
            'role'       => $role,
            'pageTitle'  => 'Úprava funkce týmu',
            'page_title' => 'Úprava funkce týmu :: ADMIN',
        ]);
    }

    /**
     * Smazání funkce. `StaffRole` NENÍ soft-deletable, takže mazání je natvrdo — proto se maže jen
     * funkce, kterou nikdo nepoužívá (jinak by mazání buď selhalo na FK, nebo osiřela obsazení).
     */
    public function delete(Request $request, int $id): RedirectResponse
    {
        $role = $this->em->find(StaffRole::class, $id);
        if (!$role instanceof StaffRole) {
            throw $this->createNotFoundException('Funkce nenalezena.');
        }
        if (!$this->isCsrfTokenValid('staff_role_delete_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Neplatný token, funkce nebyla smazána.');

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_staff_roles');
        }
        $used = $this->usageCounts()[$id] ?? 0;
        if ($used > 0) {
            $this->addFlash('danger', sprintf(
                'Funkci „%s" nelze smazat — je použitá u %d obsazení. Nejdřív je přeřaď jinam.',
                $role->getName() ?? '#'.$id,
                $used,
            ));

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_staff_roles');
        }
        $name = $role->getName() ?? '#'.$id;
        $this->em->remove($role);
        $this->em->flush();
        $this->addFlash('success', sprintf('Funkce „%s" smazána.', $name));

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_staff_roles');
    }

    /**
     * Založí chybějící funkce z výchozí sady — pomůcka pro nové nasazení (jinak by číselník zůstal
     * prázdný a rozpis služeb nepoužitelný). IDEMPOTENTNÍ: existující funkce (dle strojového klíče
     * i názvu) přeskočí, nic nepřepisuje.
     */
    public function seedDefaults(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('staff_role_seed', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Neplatný token, nic nebylo založeno.');

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_staff_roles');
        }
        $existing = $this->em->getRepository(StaffRole::class)->findAll();
        $haveTypes = [];
        $haveNames = [];
        foreach ($existing as $role) {
            $type = trim((string) $role->getType());
            if ('' !== $type) {
                $haveTypes[mb_strtolower($type)] = true;
            }
            $haveNames[mb_strtolower(trim((string) $role->getName()))] = true;
        }
        $created = 0;
        foreach (self::DEFAULT_ROLES as [$name, $type, $color, $appliesTo]) {
            if (isset($haveTypes[mb_strtolower($type)]) || isset($haveNames[mb_strtolower($name)])) {
                continue;
            }
            $role = new StaffRole(new Nameable($name, $name), $type, $color, $appliesTo);
            $role->updateSlug();
            $this->em->persist($role);
            $created++;
        }
        if ($created > 0) {
            $this->em->flush();
        }
        $this->addFlash(
            $created > 0 ? 'success' : 'info',
            $created > 0
                ? sprintf('Založeno %d chybějících funkcí z výchozí sady.', $created)
                : 'Výchozí sada už je založená — nic nepřibylo.',
        );

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_staff_roles');
    }

    /**
     * Kolikrát je která funkce použitá v obsazení (jedním dotazem — ne N+1 per řádek).
     *
     * @return array<int, int> id funkce → počet obsazení
     */
    private function usageCounts(): array
    {
        /** @var list<array{roleId: int|string|null, cnt: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(a.role) AS roleId', 'COUNT(a.id) AS cnt')
            ->from(StaffAssignment::class, 'a')
            ->where('a.role IS NOT NULL')
            ->andWhere('a.deletedAt IS NULL')
            ->groupBy('a.role')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['roleId']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
