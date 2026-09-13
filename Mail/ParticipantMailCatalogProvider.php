<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Mail;

use OswisOrg\OswisCoreBundle\Mail\Catalog\MailCatalogItem;
use OswisOrg\OswisCoreBundle\Mail\Catalog\MailCatalogProviderInterface;

/**
 * Proměnné a podmínky mailů k přihlášce. Obsah z inventury 25 kampaní 2020–2026 (analýza kap. 5b):
 * `a` a `f ? 'te' : ''` jsou nejpoužívanější; podmínky jen celé bloky (zbývá zaplatit / záloha /
 * doplatek > X / turnus). Výrazy odpovídají kontextu z ParticipantMailContextFactory.
 */
final class ParticipantMailCatalogProvider implements MailCatalogProviderInterface
{
    public function getItems(): iterable
    {
        $v = MailCatalogItem::VARIABLE;
        $c = MailCatalogItem::CONDITION;

        yield new MailCatalogItem('Příjemce', 'Oslovení (5. pád)', 'salutationName', $v);
        yield new MailCatalogItem('Příjemce', 'Jméno a příjmení', 'contact.name', $v);
        yield new MailCatalogItem('Příjemce', 'Koncovka rodu „-a" (byl/byla)', 'a', $v, mayBeEmpty: true);
        yield new MailCatalogItem('Příjemce', 'Koncovka vykání „-te" (napiš/napište)', "f ? 'te' : ''", $v, mayBeEmpty: true);
        yield new MailCatalogItem('Akce', 'Název akce', 'participant.event(false).name', $v);
        yield new MailCatalogItem('Akce', 'Krátký název akce', 'participant.event(false).shortName', $v);
        yield new MailCatalogItem('Platba', 'Variabilní symbol', 'participant.variableSymbol', $v);
        yield new MailCatalogItem('Platba', 'Cena celkem (Kč)', 'participant.price', $v);
        yield new MailCatalogItem('Platba', 'Zbývá zaplatit (Kč)', 'participant.remainingPrice', $v);
        yield new MailCatalogItem('Platba', 'Zbývá záloha (Kč)', 'participant.remainingDeposit', $v);
        yield new MailCatalogItem('Platba', 'Zbývá doplatek (Kč)', 'participant.remainingPriceRest', $v);
        yield new MailCatalogItem('Platba', 'Termín zálohy', 'paymentDeadlines.depositText', $v);
        yield new MailCatalogItem('Platba', 'Termín doplatku', 'paymentDeadlines.restText', $v);
        yield new MailCatalogItem('Podmínky', 'Jen kdo ještě nezaplatil celou částku', 'participant.remainingPrice > 0', $c);
        yield new MailCatalogItem('Podmínky', 'Jen kdo nezaplatil zálohu', 'participant.remainingDeposit > 0', $c);
        yield new MailCatalogItem('Podmínky', 'Jen kdo má doplatek nad 150 Kč', 'participant.remainingPriceRest > 150', $c);
        yield new MailCatalogItem('Podmínky', 'Jen 1. turnus', 'participant.event(false).seqId == 1', $c);
        yield new MailCatalogItem('Podmínky', 'Jen 2. turnus', 'participant.event(false).seqId == 2', $c);
    }
}
