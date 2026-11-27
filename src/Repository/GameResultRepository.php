<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameResult;
use App\Entity\GameResultRound;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameResult>
 */
class GameResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameResult::class);
    }

    /**
     * @throws \Throwable
     */
    public function persistNewResultWithRounds(GameResult $result): void
    {
        $this->getEntityManager()->wrapInTransaction(function () use ($result): void {
            $this->getEntityManager()->persist($result);
        });
    }

    public function previousBestTotalScore(string $mode, ?int $challengeSetId, int $userId): ?int
    {
        $qb = $this->createQueryBuilder('gr');
        $qb->select('MAX(gr.totalScore)')
            ->where('gr.mode = :mode')
            ->andWhere('gr.userId = :uid')
            ->setParameter('mode', $mode)
            ->setParameter('uid', $userId);

        if ($challengeSetId === null) {
            $qb->andWhere('gr.challengeSetId IS NULL');
        } else {
            $qb->andWhere('gr.challengeSetId = :csid')
                ->setParameter('csid', $challengeSetId);
        }

        $value = $qb->getQuery()->getSingleScalarResult();

        return $value === null ? null : (int) $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function leaderboardRows(string $mode, ?int $challengeSetId): array
    {
        $qb = $this->createQueryBuilder('gr')
            ->where('gr.mode = :mode')
            ->setParameter('mode', $mode);

        if ($challengeSetId === null) {
            $qb->andWhere('gr.challengeSetId IS NULL');
        } else {
            $qb->andWhere('gr.challengeSetId = :csid')
                ->setParameter('csid', $challengeSetId);
        }

        /** @var list<GameResult> $results */
        $results = $qb->getQuery()->getResult();

        $bestByIdentity = [];
        foreach ($results as $result) {
            $key = 'u' . $result->getUserId();
            $bestByIdentity[$key] = max($bestByIdentity[$key] ?? 0, $result->getTotalScore());
        }

        if ($bestByIdentity === []) {
            return [];
        }

        arsort($bestByIdentity, SORT_NUMERIC);
        $bestByIdentity = array_slice($bestByIdentity, 0, 10, true);

        $userRepo = $this->getEntityManager()->getRepository(User::class);
        $rows = [];
        foreach ($bestByIdentity as $identity => $score) {
            $userId = (int) substr($identity, 1);
            $user = $userRepo->find($userId);
            $name = $user instanceof User ? $user->getName() : 'Player';

            $rows[] = [
                'best_score' => $score,
                'name' => $name,
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $challengeSetIds
     *
     * @return array<int, int>
     */
    public function playCountsByChallengeSetIds(array $challengeSetIds): array
    {
        $challengeSetIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $challengeSetIds),
            static fn (int $id): bool => $id > 0
        ));
        if ($challengeSetIds === []) {
            return [];
        }

        /** @var list<array{setId: int|string, cnt: string|int}> $rows */
        $rows = $this->createQueryBuilder('gr')
            ->select('gr.challengeSetId AS setId', 'COUNT(gr.id) AS cnt')
            ->where('gr.challengeSetId IN (:ids)')
            ->setParameter('ids', $challengeSetIds)
            ->groupBy('gr.challengeSetId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['setId']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * @param list<int> $challengeSetIds
     *
     * @return array<int, int>
     */
    public function playCountsLast24HoursByChallengeSetIds(array $challengeSetIds): array
    {
        $challengeSetIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $challengeSetIds),
            static fn (int $id): bool => $id > 0
        ));
        if ($challengeSetIds === []) {
            return [];
        }

        /** @var list<array{setId: int|string, cnt: string|int}> $rows */
        $rows = $this->createQueryBuilder('gr')
            ->select('gr.challengeSetId AS setId', 'COUNT(gr.id) AS cnt')
            ->where('gr.challengeSetId IN (:ids)')
            ->andWhere('gr.createdAt >= :since')
            ->setParameter('ids', $challengeSetIds)
            ->setParameter('since', new \DateTimeImmutable('-24 hours'))
            ->groupBy('gr.challengeSetId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['setId']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * @return list<array{hour: string, plays: int}>
     */
    public function hourlyPlayCountsForChallengeSet(int $challengeSetId): array
    {
        if ($challengeSetId < 1) {
            return [];
        }

        $since = new \DateTimeImmutable('-7 days');

        /** @var list<array{createdAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('gr')
            ->select('gr.createdAt')
            ->where('gr.challengeSetId = :challengeSetId')
            ->andWhere('gr.createdAt >= :since')
            ->setParameter('challengeSetId', $challengeSetId)
            ->setParameter('since', $since)
            ->orderBy('gr.createdAt', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $buckets = [];
        foreach ($rows as $row) {
            $createdAt = $row['createdAt'] ?? null;
            if (!$createdAt instanceof \DateTimeInterface) {
                continue;
            }

            $hour = $createdAt->format('Y-m-d H:00:00');
            $buckets[$hour] = ($buckets[$hour] ?? 0) + 1;
        }

        $stats = [];
        foreach ($buckets as $hour => $plays) {
            $stats[] = [
                'hour' => $hour,
                'plays' => $plays,
            ];
        }

        return $stats;
    }

    /**
     * @return list<int>
     */
    public function recentLocationIdsForUser(int $userId, int $limit = 5): array
    {
        if ($userId < 1 || $limit < 1) {
            return [];
        }

        /** @var list<int|string> $gameResultIds */
        $gameResultIds = $this->createQueryBuilder('gr')
            ->select('gr.id')
            ->where('gr.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('gr.createdAt', 'DESC')
            ->setMaxResults(max($limit * 3, 10))
            ->getQuery()
            ->getSingleColumnResult();

        if ($gameResultIds === []) {
            return [];
        }

        /** @var list<array{locationId: int|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('grr.locationId AS locationId')
            ->from(GameResultRound::class, 'grr')
            ->join('grr.gameResult', 'gr')
            ->where('gr.id IN (:ids)')
            ->andWhere('grr.locationId IS NOT NULL')
            ->setParameter('ids', $gameResultIds)
            ->orderBy('gr.createdAt', 'DESC')
            ->addOrderBy('grr.roundNumber', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $seen = [];
        $locationIds = [];
        foreach ($rows as $row) {
            $locationId = (int) $row['locationId'];
            if ($locationId < 1 || isset($seen[$locationId])) {
                continue;
            }

            $seen[$locationId] = true;
            $locationIds[] = $locationId;

            if (count($locationIds) >= $limit) {
                break;
            }
        }

        return $locationIds;
    }
}
