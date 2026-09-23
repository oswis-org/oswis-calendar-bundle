<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailCategory;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ParticipantMailCategoryEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ParticipantMailGroupEditType;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\TwigTemplateEditType;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMailer;
use OswisOrg\OswisCoreBundle\Entity\AppUserMail\AppUserMailGroup;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use OswisOrg\OswisCoreBundle\Mail\Parent\MailParentRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Source;

/**
 * Web admin CRUD over the mail templating chain so admins don't have to
 * edit ParticipantMailGroup / ParticipantMailCategory / TwigTemplate rows
 * directly in the DB.
 *
 * Scope: index page + edit + create (optionally prefilled via ?from=<id> —
 * covers the yearly season setup: clone last year's groups/categories and
 * adjust the year). DELETE stays unexposed (rare enough for DB access).
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminMailConfigController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantRepository $participantRepository,
        private readonly ParticipantManualMailer $manualMailer,
        private readonly Environment $twig,
        private readonly MailParentRegistry $parentRegistry,
    ) {
    }

    public function index(): Response
    {
        // ParticipantMailGroup::$type is derived from category->getType() — not
        // a column — so `findBy(orderBy: ['type'])` raises UnrecognizedField.
        // Order by id ASC for stable display; admin can switch to category-sort later.
        $groups = $this->em->getRepository(ParticipantMailGroup::class)
            ->findBy([], ['id' => 'ASC']);
        $categories = $this->em->getRepository(ParticipantMailCategory::class)
            ->findBy([], ['priority' => 'ASC', 'id' => 'ASC']);
        $templates = $this->em->getRepository(TwigTemplate::class)
            ->findBy([], ['id' => 'ASC']);

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/index.html.twig', [
            'groups'     => $groups,
            'categories' => $categories,
            'templates'  => $templates,
            'pouziti'    => $this->pouzitiSablon($groups),
            // Tytéž lidské popisky obálek jako ve výběru „Vychází z" — ne syrová cesta k souboru.
            'obalky'     => array_map(static fn ($o): string => $o->label, $this->parentRegistry->all()),
            'pageTitle'  => 'Konfigurace e-mailů',
            'page_title' => 'Konfigurace e-mailů :: ADMIN',
        ]);
    }

    /**
     * Které mailové skupiny kterou šablonu používají — ze VŠECH řetězců, ne jen z přihlášek.
     *
     * PROČ: v seznamu šablon se za roky nasčítaly ročníkové kopie (2020 … 2026) a nebylo z něj
     * poznat, která ještě někam patří a která je jen historie — takže se nikdo neodvážil nic
     * uklidit. Tohle je ten chybějící článek „kde se to používá".
     *
     * ⚠️ Řetězce jsou DVA a jsou na sobě nezávislé: `ParticipantMailGroup` (kalendář — maily
     * k přihláškám) a `AppUserMailGroup` (core — maily k účtu: ověření, aktivace, změna hesla).
     * Když se počítal jen ten první, hlásil sloupec u šesti šablon „nepoužívá se" — mimo jiné
     * u ověření účtu a změny hesla, tedy u nejcitlivější pošty v systému. Výtka uživatele
     * 23. 9. 2026; takové tvrzení svádí k jejich smazání.
     *
     * @param list<ParticipantMailGroup> $groups
     *
     * @return array<int, list<string>> id šablony → názvy skupin (s označením řetězce)
     */
    private function pouzitiSablon(array $groups): array
    {
        $pouziti = [];
        foreach ($groups as $group) {
            $templateId = $group->getTwigTemplate()?->getId();
            if (null === $templateId) {
                continue;
            }
            $pouziti[$templateId][] = ($group->getName() ?? '#'.$group->getId()).' (přihlášky)';
        }
        foreach ($this->em->getRepository(AppUserMailGroup::class)->findBy([], ['id' => 'ASC']) as $group) {
            $templateId = $group->getTwigTemplate()?->getId();
            if (null === $templateId) {
                continue;
            }
            $pouziti[$templateId][] = ($group->getName() ?? '#'.$group->getId()).' (účty)';
        }

        return $pouziti;
    }

    public function editGroup(Request $request, int $id): Response
    {
        $group = $this->em->find(ParticipantMailGroup::class, $id)
            ?? throw $this->createNotFoundException('Mail group nenalezena.');
        $form = $this->createForm(ParticipantMailGroupEditType::class, $group);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($group);
            $this->em->flush();
            $this->addFlash('success', sprintf('Mail group „%s" uložena.', $group->getName() ?? '#'.$id));

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_mail_config'));
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'       => $form,
            'entity'     => $group,
            'kind'       => 'group',
            'pageTitle'  => sprintf('Mail group: %s', $group->getName() ?? '#'.$id),
            'page_title' => sprintf('Mail group: %s :: ADMIN', $group->getName() ?? '#'.$id),
        ]);
    }

    public function editCategory(Request $request, int $id): Response
    {
        $category = $this->em->find(ParticipantMailCategory::class, $id)
            ?? throw $this->createNotFoundException('Mail kategorie nenalezena.');
        $form = $this->createForm(ParticipantMailCategoryEditType::class, $category);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($category);
            $this->em->flush();
            $this->addFlash('success', sprintf('Mail kategorie „%s" uložena.', $category->getName() ?? '#'.$id));

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_mail_config'));
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'       => $form,
            'entity'     => $category,
            'kind'       => 'category',
            'pageTitle'  => sprintf('Mail kategorie: %s', $category->getName() ?? '#'.$id),
            'page_title' => sprintf('Mail kategorie: %s :: ADMIN', $category->getName() ?? '#'.$id),
        ]);
    }

    /**
     * Create a new ParticipantMailGroup, optionally prefilled from an existing one (?from=<id>).
     * The date window and automaticMailing flag are deliberately NOT copied: an empty window means
     * "always applies", so a freshly cloned group must start disabled and windowless — enabling it
     * is a separate, explicit decision after the window is set.
     */
    public function newGroup(Request $request): Response
    {
        $group = new ParticipantMailGroup();
        $fromId = $request->query->getInt('from');
        $from = $fromId > 0 ? $this->em->find(ParticipantMailGroup::class, $fromId) : null;
        if ($from instanceof ParticipantMailGroup) {
            $group->setName($from->getName());
            $group->setShortName($from->getShortName());
            $group->setDescription($from->getDescription());
            $group->setPriority($from->getPriority());
            $group->setTwigTemplate($from->getTwigTemplate());
            $group->setCategory($from->getCategory());
            $group->setEvent($from->getEvent());
            $group->setOnlyActive($from->isOnlyActive());
            $group->setFilterExpression($from->getFilterExpression());
        }
        $form = $this->createForm(ParticipantMailGroupEditType::class, $group);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // The group form has no slug field and nothing else calls updateSlug(), so a
            // form-created group would persist with slug NULL (unlike the historic rows).
            $group->updateSlug();
            $this->em->persist($group);
            $this->em->flush();
            $this->addFlash('success', sprintf(
                'Mail group „%s" založena%s.',
                $group->getName() ?? '#'.$group->getId(),
                $group->isAutomaticMailing() ? '' : ' (automatické rozesílání je vypnuté)',
            ));

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_mail_config'));
        }

        $pageTitle = null !== $from ? sprintf('Nová mailová skupina (kopie „%s")', $from->getName() ?? '#'.$fromId) : 'Nová mailová skupina';

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'       => $form,
            'entity'     => $group,
            'kind'       => 'group',
            'pageTitle'  => $pageTitle,
            'page_title' => $pageTitle.' :: ADMIN',
        ]);
    }

    /**
     * Create a new ParticipantMailCategory, optionally prefilled via ?from=<id>. The auto-mail
     * engine deduplicates per category type ("one mail of this type per participant"), so a
     * duplicate type would silently split one campaign across two categories — rejected here.
     */
    public function newCategory(Request $request): Response
    {
        $category = new ParticipantMailCategory();
        $fromId = $request->query->getInt('from');
        $from = $fromId > 0 ? $this->em->find(ParticipantMailCategory::class, $fromId) : null;
        if ($from instanceof ParticipantMailCategory) {
            $category->setName($from->getName());
            $category->setShortName($from->getShortName());
            $category->setDescription($from->getDescription());
            $category->setType($from->getType());
            $category->setPriority($from->getPriority());
        }
        $form = $this->createForm(ParticipantMailCategoryEditType::class, $category);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $type = $category->getType();
            // DQL, not findOneBy: the entity is in the NONSTRICT L2 cache region, and findOneBy can
            // return a stale ghost for a row deleted outside the ORM — which would block a legitimate
            // re-creation here. Plain DQL always reads the database.
            $duplicate = empty($type) ? null
                : $this->em->createQueryBuilder()
                    ->select('c')->from(ParticipantMailCategory::class, 'c')
                    ->where('c.type = :type')->setParameter('type', $type)
                    ->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if ($duplicate instanceof ParticipantMailCategory) {
                $form->get('type')->addError(new FormError(sprintf(
                    'Kategorie s typem „%s" už existuje (#%d „%s") — typ musí být unikátní, jinak se kampaň rozpadne mezi dvě kategorie.',
                    $type,
                    $duplicate->getId() ?? 0,
                    $duplicate->getName() ?? '?',
                )));
            } else {
                $this->em->persist($category);
                $this->em->flush();
                $this->addFlash('success', sprintf('Mail kategorie „%s" založena.', $category->getName() ?? '#'.$category->getId()));

                return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_mail_config'));
            }
        }

        $pageTitle = null !== $from ? sprintf('Nová kategorie e-mailů (kopie „%s")', $from->getName() ?? '#'.$fromId) : 'Nová kategorie e-mailů';

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'       => $form,
            'entity'     => $category,
            'kind'       => 'category',
            'pageTitle'  => $pageTitle,
            'page_title' => $pageTitle.' :: ADMIN',
        ]);
    }

    /**
     * Create a new TwigTemplate (e.g. a kind=snippet block or a new campaign). Reuses the edit form;
     * on save it redirects to the editor so the admin immediately gets the live preview. Closes the
     * loop for snippets (the bulk composer inserts them by slug) without needing direct DB access.
     */
    public function newTemplate(Request $request): Response
    {
        $template = new TwigTemplate();
        $fromId = $request->query->getInt('from');
        $from = $fromId > 0 ? $this->em->find(TwigTemplate::class, $fromId) : null;
        if ($from instanceof TwigTemplate) {
            $template->setName($from->getName());
            $template->setShortName($from->getShortName());
            $template->setDescription($from->getDescription());
            $template->setKind($from->getKind());
            $template->setRegularTemplateName($from->getRegularTemplateName());
            $template->setTextValue($from->getTextValue());
            // Slug se ZÁMĚRNĚ nekopíruje: šablona se hledá podle sluga a při shodě vyhraje
            // ta s nižším id — kopie se stejným slugem by se tedy tiše nikdy nepoužila.
            $template->setForcedSlug($this->navrhnoutSlugKopie($from));
        }
        $form = $this->createForm(TwigTemplateEditType::class, $template, ['preview' => $this->nahledSablony(), 'rodice' => $this->parentRegistry->choices($template->getRegularTemplateName())]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && !$this->templateHasErrors($form, $template)) {
            $this->em->persist($template);
            $this->em->flush();
            $this->addFlash('success', sprintf('Twig šablona „%s" vytvořena.', $template->getName() ?? '#'.$template->getId()));

            return new RedirectResponse($this->generateUrl(
                'oswis_org_oswis_calendar_web_admin_mail_template_edit',
                ['id' => $template->getId()],
            ));
        }

        $pageTitle = $from instanceof TwigTemplate
            ? sprintf('Nová šablona (kopie „%s")', $from->getName() ?? '#'.$fromId)
            : 'Nová Twig šablona';

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'               => $form,
            'entity'             => $template,
            'kind'               => 'template',
            'pageTitle'          => $pageTitle,
            'page_title'         => $pageTitle.' :: ADMIN',
        ]);
    }

    /**
     * Slug pro kopii šablony — takový, který ještě není obsazený.
     *
     * Ročníkové kopie (`…-2025-1-turnus-feedback` → `…-2026-1-turnus-feedback`) jsou tu naprostá
     * většina, takže když slug obsahuje ročník, posune se o rok; jinak se přidá `-kopie`. Je to
     * jen NÁVRH — pole zůstává k ruční úpravě. Důležité je, že návrh nikdy nekoliduje: při shodě
     * slugů vyhraje řádek s nižším id, takže kopie se stejným slugem by se tiše nikdy nepoužila.
     */
    private function navrhnoutSlugKopie(TwigTemplate $from): string
    {
        $slug = $from->getSlug();
        if (1 === preg_match('~^(.*?)(20\d{2})(.*)$~', $slug, $shoda)) {
            $posunuty = $shoda[1].((int) $shoda[2] + 1).$shoda[3];
            if (!$this->slugJeObsazeny($posunuty)) {
                return $posunuty;
            }
        }
        $zaklad = $slug.'-kopie';
        $kandidat = $zaklad;
        for ($poradi = 2; $this->slugJeObsazeny($kandidat); ++$poradi) {
            $kandidat = $zaklad.'-'.$poradi;
        }

        return $kandidat;
    }

    /** Je slug už zabraný jinou šablonou? (Kontroluje i `forcedSlug`, ze kterého se slug počítá.) */
    private function slugJeObsazeny(string $slug, ?int $kromeId = null): bool
    {
        if ('' === $slug) {
            return false;
        }
        $dotaz = $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')->from(TwigTemplate::class, 't')
            ->where('t.slug = :slug OR t.forcedSlug = :slug')
            ->setParameter('slug', $slug);
        if (null !== $kromeId) {
            $dotaz->andWhere('t.id <> :krome')->setParameter('krome', $kromeId);
        }

        return ((int) $dotaz->getQuery()->getSingleScalarResult()) > 0;
    }

    public function editTemplate(Request $request, int $id): Response
    {
        $template = $this->em->find(TwigTemplate::class, $id)
            ?? throw $this->createNotFoundException('Twig šablona nenalezena.');
        // Formulář edituje `forcedSlug`; u řádků, kde vyplněný není (šablony založené tímhle
        // formulářem), by se jinak ukázalo prázdno a uložení by slug přepsalo na odvozený z názvu —
        // u dvou šablon se stejným názvem tedy na tentýž. Předvyplníme tím, co dnes reálně platí.
        if (null === $template->getForcedSlug()) {
            $template->setForcedSlug($template->getSlug());
        }
        $form = $this->createForm(TwigTemplateEditType::class, $template, ['preview' => $this->nahledSablony(), 'rodice' => $this->parentRegistry->choices($template->getRegularTemplateName())]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && !$this->templateHasErrors($form, $template, $id)) {
            $this->em->persist($template);
            $this->em->flush();
            $this->addFlash('success', sprintf('Twig šablona „%s" uložena.', $template->getName() ?? '#'.$id));

            return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_mail_config'));
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/mail_config/edit.html.twig', [
            'form'               => $form,
            'entity'             => $template,
            'kind'               => 'template',
            'pageTitle'          => sprintf('Šablona e-mailu: %s', $template->getName() ?? '#'.$id),
            'page_title'         => sprintf('Šablona e-mailu: %s :: ADMIN', $template->getName() ?? '#'.$id),
        ]);
    }

    /**
     * Kontrola kampaně / bloku před uložením (nález B5: překlep se uložil a rozbilo se až odeslání).
     * Chyby = u pole obsahu a nic se neuloží; varování = po uložení jako upozornění. Systémové šablony
     * se tu nekontrolují — potřebují data konkrétního mailu (platba…), která vzorová přihláška nemá.
     *
     * Slug se kontroluje u VŠECH druhů: šablona se hledá podle něj a při shodě vyhraje řádek
     * s nižším id, takže duplicitní slug by tiše umlčel novější šablonu.
     *
     * @param FormInterface<mixed> $form
     */
    private function templateHasErrors(FormInterface $form, TwigTemplate $template, ?int $id = null): bool
    {
        // Obě kontroly vždy (ne `||`): každá hlásí u jiného pole a uživatel má vidět všechny chyby naráz.
        $slugSpatne = $this->slugMaProblem($form, $template, $id);
        $bezObalky = $this->kampanBezObalky($form, $template);
        $zakladniChyba = $slugSpatne || $bezObalky;
        if ('' === trim((string) $template->getTextValue())) {
            return $zakladniChyba; // jen rodič = mail podle souboru, není co kontrolovat
        }
        // Kontroluje se SLOŽENÝ zdroj (rodič + text) — tak, jak ho uvidí odeslání.
        $source = $template->getSlozenyZdroj();
        if (!in_array($template->getKind(), [TwigTemplate::KIND_CAMPAIGN, TwigTemplate::KIND_SNIPPET], true)) {
            // Systémovou šablonu se vzorovou přihláškou vykreslit nejde (potřebuje platbu, token…),
            // ale ZÁPIS se ověřit dá vždy — hlavně text mimo bloky, který by u šablony s rodičem
            // shodil každé odeslání (Twig ho odmítne už při překladu).
            try {
                $this->twig->parse($this->twig->tokenize(new Source($source, 'kontrola šablony')));
            } catch (SyntaxError $chyba) {
                $zprava = str_contains($chyba->getRawMessage(), 'outside Twig blocks')
                    ? 'Šablona vychází z jiné šablony, takže text smí být jen uvnitř bloků — text mimo bloky by shodil každé odeslání.'
                    : 'Chyba v zápisu šablony: '.$chyba->getRawMessage();
                $form->get('textValue')->addError(new FormError($zprava));

                return true;
            }

            return $zakladniChyba;
        }
        $validation = $this->manualMailer->validateTemplateSource($source, $this->participantRepository->findSampleParticipants(3));
        foreach ($validation->errors() as $problem) {
            $form->get('textValue')->addError(new FormError($problem->message));
        }
        foreach ($validation->hasErrors() ? [] : $validation->warnings() as $problem) {
            $this->addFlash('warning', $problem->message);
        }

        return $zakladniChyba || $validation->hasErrors();
    }

    /**
     * Kampaň je CELÝ e-mail — musí z něčeho vycházet (obálka dodá hlavičku, oslovení, podpis a patičku).
     *
     * PROČ: kampaň #35 (13. 9. 2026) se uložila jako holý text bez obálky. Poslala se správně v režimu
     * „tělo zprávy" (ten obálku přidá sám), ale v nabídce kampaní hromadného mailu zůstala past: vybraná
     * jako kampaň by odešla jen jako pár odstavců bez hlavičky a podpisu. Samotný text k vložení do zprávy
     * je Blok. Rodič v textu (`{% extends %}` na začátku — data před převodem 23. 9. 2026) se počítá taky.
     *
     * @param FormInterface<mixed> $form
     */
    private function kampanBezObalky(FormInterface $form, TwigTemplate $template): bool
    {
        if (TwigTemplate::KIND_CAMPAIGN !== $template->getKind()
            || 1 === preg_match('/^\s*\{%-?\s*extends\b/', $template->getSlozenyZdroj())) {
            return false;
        }
        $form->get('regularTemplateName')->addError(new FormError(
            'Kampaň je celý e-mail — vyber, z čeho vychází (obvykle „Obecná zpráva"). Bez obálky by odešla bez hlavičky, oslovení, podpisu a patičky. Samotný text k vložení do zprávy patří do druhu „Blok".',
        ));

        return true;
    }

    /**
     * Slug musí být vyplněný a jedinečný — jinak by šablona zůstala nedosažitelná.
     *
     * @param FormInterface<mixed> $form
     */
    private function slugMaProblem(FormInterface $form, TwigTemplate $template, ?int $id): bool
    {
        $slug = $template->getForcedSlug() ?? '';
        if ('' === $slug) {
            $form->get('forcedSlug')->addError(new FormError(
                'Vyplň slug. Bez něj se slug odvodí z názvu — a dvě šablony se stejným názvem by pak měly tentýž.',
            ));

            return true;
        }
        if ($this->slugJeObsazeny($slug, $id)) {
            $form->get('forcedSlug')->addError(new FormError(sprintf(
                'Slug „%s" už jiná šablona má. Při shodě se použije ta starší, takže tahle by se nikdy neodeslala.',
                $slug,
            )));

            return true;
        }

        return false;
    }

    /**
     * Náhled v editoru šablony = TENTÝŽ sjednocený náhled jako Nová zpráva a hromadný mail
     * (`/web_admin/zpravy/nahled`): vedle textu, s předmětem a s kontrolou. Do 23. 9. 2026 tu byl vlastní
     * blok pod formulářem s vlastním JS, vlastní routou a vlastním CSRF — neukazoval předmět ani chyby
     * a fungoval jen u uložené šablony.
     *
     * `document: true` — kampaň je celý Twig dokument, ne tělo zprávy; bloky (kind=snippet) pozná
     * server podle obsahu a zabalí je do obálky jako běžný text. Předmětem je pole „Název".
     *
     * @return array{url: string, recipients: list<array{id: int, label: string}>, subjectField: string, templateField: string, document: bool, parentField: string}
     */
    private function nahledSablony(): array
    {
        $recipients = [];
        foreach ($this->participantRepository->findSampleParticipants(30) as $participant) {
            if (null === ($id = $participant->getId())) {
                continue;
            }
            // Jméno z čistého getteru — `getName()` entitu mění (a přes L2 cache by se to propsalo).
            $contact = $participant->getContact();
            $event = $participant->getEvent();
            $recipients[] = ['id' => $id, 'label' => trim(sprintf(
                '#%d %s%s',
                $id,
                $contact instanceof Person ? $contact->getFullName() : '',
                null !== $event ? ' · '.($event->getShortName() ?? $event->getName() ?? '') : '',
            ))];
        }

        return [
            'url'           => $this->generateUrl('oswis_org_oswis_calendar_web_admin_message_preview'),
            'recipients'    => $recipients,
            'subjectField'  => 'twig_template_edit_name',
            'templateField' => '',
            'document'      => true,
            'parentField'   => 'twig_template_edit_regularTemplateName',
        ];
    }
}
