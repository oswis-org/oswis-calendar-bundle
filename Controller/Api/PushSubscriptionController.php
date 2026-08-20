<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Push\PushSubscription;
use OswisOrg\OswisCalendarBundle\Repository\Push\PushSubscriptionRepository;
use OswisOrg\OswisCalendarBundle\Service\Push\PushNotificationService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Registrace zařízení pro push notifikace.
 *
 * Appka si vyžádá svolení v prohlížeči, dostane adresu schránky a klíče, a pošle je sem.
 * Odběr patří PŘIHLÁŠENÉMU ÚČTU — proto `ROLE_CUSTOMER` a uživatel se bere ze session,
 * nikdy z těla požadavku (jinak by šlo přihlásit cizí zařízení k odběru za někoho jiného).
 */
class PushSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly PushNotificationService $push,
    ) {
    }

    /** Veřejný VAPID klíč — appka ho potřebuje, než si o odběr řekne. */
    public function verejnyKlic(): JsonResponse
    {
        return new JsonResponse([
            'publicKey' => $this->push->verejnyKlic(),
            'enabled'   => $this->push->jeNastaveno(),
        ]);
    }

    #[IsGranted('ROLE_CUSTOMER')]
    public function registruj(Request $request): Response
    {
        $uzivatel = $this->getUser();
        if (!$uzivatel instanceof AppUser) {
            return new JsonResponse(['error' => 'Nepřihlášený uživatel.'], Response::HTTP_UNAUTHORIZED);
        }
        /** @var array{endpoint?: string, keys?: array{p256dh?: string, auth?: string}} $data */
        $data = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $endpoint = (string) ($data['endpoint'] ?? '');
        $p256dh = (string) ($data['keys']['p256dh'] ?? '');
        $auth = (string) ($data['keys']['auth'] ?? '');
        if ('' === $endpoint || '' === $p256dh || '' === $auth) {
            return new JsonResponse(['error' => 'Neúplný odběr.'], Response::HTTP_BAD_REQUEST);
        }

        // Táž adresa schránky = totéž zařízení. Když se na něm přihlásí někdo jiný, odběr se
        // PŘEPÍŠE na nového uživatele — jinak by novému člověku chodily vzkazy toho předchozího.
        $odber = $this->subscriptions->najdiPodleEndpointu($endpoint);
        if (null === $odber) {
            $odber = new PushSubscription($uzivatel, $endpoint, $p256dh, $auth);
            $this->em->persist($odber);
        } else {
            $odber->prepisNa($uzivatel, $p256dh, $auth);
        }
        $this->em->flush();

        return new JsonResponse(['ok' => true], Response::HTTP_CREATED);
    }

    /**
     * Zrušení odběru.
     *
     * ⚠️ Ruší se JEN VLASTNÍ odběr. Dřív se mazalo rovnou podle adresy schránky z těla požadavku,
     * takže kdokoli přihlášený mohl odhlásit cizí zařízení, pokud jeho adresu znal — a tím
     * někomu jinému potichu vypnout upozornění týmu. Adresu sice nejde uhodnout (je dlouhá
     * a náhodná), ale „těžko uhodnutelné" není oprávnění: uniknout může z logu, ze zálohy
     * i ze samotné aplikace na sdíleném zařízení.
     *
     * Odpověď je stejná, ať se něco smazalo nebo ne — jinak by šlo zjišťovat, které adresy
     * v systému existují.
     */
    #[IsGranted('ROLE_CUSTOMER')]
    public function zrus(Request $request): Response
    {
        $uzivatel = $this->getUser();
        if (!$uzivatel instanceof AppUser) {
            return new JsonResponse(['ok' => true]);
        }
        /** @var array{endpoint?: string} $data */
        $data = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $odber = $this->subscriptions->najdiPodleEndpointu((string) ($data['endpoint'] ?? ''));
        if (null !== $odber && $odber->getAppUser() === $uzivatel) {
            $this->em->remove($odber);
            $this->em->flush();
        }

        return new JsonResponse(['ok' => true]);
    }
}
