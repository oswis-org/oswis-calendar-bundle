<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\WebAdmin;

use Symfony\Component\HttpFoundation\Request;

/**
 * Kam se vrátit po POST akci v adminu (smazání, check-in, hromadná akce): bere se `Referer`,
 * tedy stránka, na které formulář byl.
 *
 * **Proč ne skryté pole s URL (bylo tak do 2026-07-30):** formuláře posílaly `return` s celou
 * adresou seznamu. Jakmile byl seznam seřazený, hodnota obsahovala `&sort=` — a OWASP CRS to
 * vyhodnotí jako shell injection („`&` a za ním příkaz `sort`", pravidlo 932115) a request
 * zablokuje. Uživatel viděl chybovou stránku a přihláška se nesmazala. Adresa v těle requestu je
 * na WAFu obecně křehká (kterékoli pravidlo nad ARGS ji může takhle chytit), zatímco `Referer`
 * je hlavička, na kterou tahle třída pravidel necílí.
 *
 * Hlavička je spolehlivá, protože jde o **same-origin** POST z formuláře: politika
 * `strict-origin-when-cross-origin` u same-origin posílá plnou URL včetně query. Když přesto
 * chybí nebo nevyhoví, volající použije svůj výchozí cíl — nikdy se nevěří cizí adrese
 * (ochrana proti open-redirectu).
 */
final class AdminReturnUrl
{
    /**
     * Relativní same-origin cesta (path + query) s daným prefixem, nebo null.
     */
    public static function fromReferer(Request $request, string $prefix = '/web_admin/'): ?string
    {
        $referer = (string) $request->headers->get('referer', '');
        if ('' === $referer || str_contains($referer, "\n") || str_contains($referer, "\r")) {
            return null;
        }
        $parts = parse_url($referer);
        if (false === $parts) {
            return null;
        }
        // Absolutní URL musí být na tomtéž hostu; relativní (bez hostu) je same-origin z definice.
        $host = $parts['host'] ?? null;
        if (null !== $host && 0 !== strcasecmp($host, $request->getHost())) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }
        $query = ($parts['query'] ?? '') !== '' ? '?'.$parts['query'] : '';

        return $path.$query;
    }
}
