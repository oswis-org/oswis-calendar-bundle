<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Push;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Push\PushSubscription;

/**
 * Odběry push notifikací.
 *
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /** Odběr téhož zařízení — pozná se podle otisku adresy schránky, ne podle uživatele. */
    public function najdiPodleEndpointu(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpointHash' => hash('sha256', $endpoint)]);
    }

    /**
     * Odběry lidí, kterým je vzkaz určen.
     *
     * Cílení kopíruje `Announcement` (od nejširšího): celý turnus · jedna skupina/páska ·
     * jeden účastník. Vazba na člověka vede přes přihlášku → kontakt → uživatelský účet,
     * protože odběr patří ÚČTU (jedno zařízení = jeden přihlášený člověk), kdežto cílení
     * se dělá nad přihláškami.
     *
     * ⚠️ Smazané přihlášky se vylučují ručně — globální filtr `softdeleteable` zapnutý NENÍ.
     *
     * @return list<PushSubscription>
     */
    public function proCil(int $eventId, ?int $groupId = null, ?int $participantId = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->innerJoin(\OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser::class, 'u', 'WITH', 'u = s.appUser')
            ->innerJoin(
                \OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact::class,
                'c',
                'WITH',
                'c.appUser = u',
            )
            ->innerJoin(
                \OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantContact::class,
                'pc',
                'WITH',
                'pc.contact = c AND pc.deletedAt IS NULL',
            )
            ->innerJoin(
                \OswisOrg\OswisCalendarBundle\Entity\Participant\Participant::class,
                'p',
                'WITH',
                'p = pc.participant AND p.deletedAt IS NULL',
            )
            ->innerJoin('p.event', 'e')
            ->andWhere('e.id = :event OR e.superEvent = :event')
            ->setParameter('event', $eventId);
        if (null !== $participantId) {
            $qb->andWhere('p.id = :participant')->setParameter('participant', $participantId);
        } elseif (null !== $groupId) {
            $qb->andWhere('IDENTITY(p.group) = :group')->setParameter('group', $groupId);
        }

        /** @var list<PushSubscription> $vysledek */
        $vysledek = $qb->distinct()->getQuery()->getResult();

        return $vysledek;
    }
}
