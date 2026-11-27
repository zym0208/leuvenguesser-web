<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findUserById(int $id): ?User
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findOneByName(string $name): ?User
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * @return list<User>
     */
    public function topByTotalScore(int $limit = 10): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.leuven_score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<User>
     */
    public function topByChallengeScore(int $limit = 10): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.challenge_score > 0')
            ->orderBy('u.challenge_score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<User>
     */
    public function topByDuelScore(int $limit = 10): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.duel_score > 0')
            ->orderBy('u.duel_score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(int $limit = 200): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->orderBy('u.leuven_score', 'DESC')
            ->addOrderBy('u.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (User $user): array => [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'roles' => $user->getRoles(),
            'isAdmin' => $user->isAdmin(),
        ], $users);
    }
}
