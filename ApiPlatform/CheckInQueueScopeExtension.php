<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Fronta příjezdového odbavení musí být VŽDY omezená na jeden turnus.
 *
 * Operace `/participant_check_in_queue` má `paginationEnabled: false` (záměr — u stolu se
 * nestránkuje, viz konvence scoped seznamů) a její popis počítá s tím, že se volá s `?event.id=`.
 * Vynuceno to ale nebylo, takže volání BEZ filtru vrátilo úplně všechny přihlášky ze všech
 * ročníků. Změřeno na klonu 2026-08-15:
 *
 * | volání | položek | velikost | doba |
 * |--------|---------|----------|------|
 * | s `?event.id=` (tak volá appka) | 110 | 51 kB | 2 s |
 * | bez filtru | **2 953** | **1,2 MB** | **47 s** |
 *
 * Čtyřicet sedm sekund drží PHP-FPM worker a spustí to kdokoliv z týmu (stačí `ROLE_MANAGER`)
 * jedním omylem — třeba otevřením URL bez parametrů. Zrovna v den příjezdu, na kempové wifi,
 * je tohle přesně to, co stůl zastaví.
 *
 * Odpovídá se **400 s vysvětlením**, ne prázdným seznamem: prázdný seznam by u stolu vypadal
 * jako „nikdo tu není" a to je horší než chyba, kterou je vidět.
 *
 * Appky se to nedotkne — `ParticipantsService::getCheckInQueue()` posílá `event.id` vždy.
 */
final readonly class CheckInQueueScopeExtension implements QueryCollectionExtensionInterface
{
    private const string URI = '/participant_check_in_queue';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        // `getUriTemplate()` je až na `HttpOperation`, ne na základním `Operation`. Porovnává se
        // předponou, protože API Platform k šabloně přidává `.{_format}`.
        if (Participant::class !== $resourceClass
            || !$operation instanceof HttpOperation
            || !str_starts_with((string) $operation->getUriTemplate(), self::URI)) {
            return;
        }
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return; // mimo HTTP (testy, konzole) — není co vynucovat
        }
        if (!self::maRozsah($request->query->all())) {
            throw new BadRequestHttpException(
                'Fronta odbavení se musí omezit na jeden turnus: doplň parametr `event.id`. '
                .'Bez něj by odpověď obsahovala přihlášky ze všech ročníků.',
            );
        }
    }

    /**
     * ⚠️ `event.id=47` NEDORAZÍ pod klíčem `event.id` — **PHP tečku v názvu parametru přepisuje na
     * podtržítko**, takže v query bagu je `event_id`. (Ověřeno, ne odhadnuto: naivní čtení `event`
     * odmítlo i volání z appky.) Bere se proto i tvar `event[id]=` a `event=` (IRI), aby se guard
     * nestal past pro jiného klienta.
     *
     * @param array<string, mixed> $query
     */
    private static function maRozsah(array $query): bool
    {
        foreach ([$query['event_id'] ?? null, $query['event'] ?? null] as $hodnota) {
            if (is_array($hodnota)) {
                $hodnota = $hodnota['id'] ?? null;
            }
            if (null !== $hodnota && '' !== $hodnota && [] !== $hodnota) {
                return true;
            }
        }

        return false;
    }
}
