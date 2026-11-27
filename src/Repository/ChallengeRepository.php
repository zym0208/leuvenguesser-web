<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChallengeComment;
use App\Entity\ChallengeLike;
use App\Entity\ChallengeSet;
use App\Entity\ChallengeSetLocation;
use App\Entity\Location;
use App\Entity\Region;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChallengeSet>
 */
class ChallengeRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly RegionRepository $regions,
        private readonly LocationRepository $locations,
        private readonly GameResultRepository $gameResults,
    ) {
        parent::__construct($registry, ChallengeSet::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRegionById(int $id): ?array
    {
        return $this->regions->findRegionRowById($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRegionBySlug(string $slug): ?array
    {
        return $this->regions->findRegionRowBySlug($slug);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRegionForChallengeLocation(int $challengeSetId, int $locationId): ?array
    {
        return $this->regions->findRegionRowForChallengeLocation($challengeSetId, $locationId);
    }

    public function findOrCreateRegion(
        string $name,
        float $centerLat,
        float $centerLng,
        ?float $swLat = null,
        ?float $swLng = null,
        ?float $neLat = null,
        ?float $neLng = null
    ): int {
        return $this->regions->findOrCreateRegion($name, $centerLat, $centerLng, $swLat, $swLng, $neLat, $neLng);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listChallengeSets(array $filters, ?int $userId = null): array
    {
        $qb = $this->createQueryBuilder('cs')
            ->select('cs', 'r')
            ->join('cs.region', 'r')
            ->where('cs.published = true');

        if (($filters['q'] ?? '') !== '') {
            $qb->andWhere('cs.title LIKE :q OR cs.description LIKE :q')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }

        if (($filters['author'] ?? '') !== '') {
            $qb->andWhere('cs.authorName LIKE :author')
                ->setParameter('author', '%' . $filters['author'] . '%');
        }

        if (($filters['region'] ?? '') !== '') {
            $qb->andWhere('r.slug = :region OR r.name LIKE :region_name')
                ->setParameter('region', $filters['region'])
                ->setParameter('region_name', '%' . $filters['region'] . '%');
        }

        /** @var list<ChallengeSet> $sets */
        $sets = $qb->getQuery()->getResult();
        if ($sets === []) {
            return [];
        }

        $setIds = array_map(static fn (ChallengeSet $set): int => (int) $set->getId(), $sets);
        $roundCounts = $this->batchRoundCounts($setIds);
        $playCounts = $this->gameResults->playCountsByChallengeSetIds($setIds);
        $plays24h = $this->gameResults->playCountsLast24HoursByChallengeSetIds($setIds);
        $likedSetIds = $this->likedSetIdsForUser($setIds, $userId);

        $rows = [];
        foreach ($sets as $set) {
            $id = (int) $set->getId();
            $rows[] = $this->mapChallengeListRow(
                $set,
                $roundCounts[$id] ?? 0,
                $playCounts[$id] ?? 0,
                $plays24h[$id] ?? 0,
                isset($likedSetIds[$id])
            );
        }

        $this->sortChallengeListRows($rows, (string) ($filters['sort'] ?? 'recommended'));

        return array_slice($rows, 0, 60);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listChallengeSetsForAdmin(array $filters): array
    {
        $qb = $this->createQueryBuilder('cs')
            ->select('cs', 'r')
            ->join('cs.region', 'r');

        if (($filters['q'] ?? '') !== '') {
            $qb->andWhere('cs.title LIKE :q OR cs.description LIKE :q')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }

        if (($filters['author'] ?? '') !== '') {
            $qb->andWhere('cs.authorName LIKE :author')
                ->setParameter('author', '%' . $filters['author'] . '%');
        }

        if (($filters['region'] ?? '') !== '') {
            $qb->andWhere('r.slug = :region OR r.name LIKE :region_name')
                ->setParameter('region', $filters['region'])
                ->setParameter('region_name', '%' . $filters['region'] . '%');
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'published') {
            $qb->andWhere('cs.published = true');
        } elseif ($status === 'hidden') {
            $qb->andWhere('cs.published = false');
        }

        /** @var list<ChallengeSet> $sets */
        $sets = $qb->getQuery()->getResult();
        if ($sets === []) {
            return [];
        }

        $setIds = array_map(static fn (ChallengeSet $set): int => (int) $set->getId(), $sets);
        $roundCounts = $this->batchRoundCounts($setIds);
        $playCounts = $this->gameResults->playCountsByChallengeSetIds($setIds);
        $plays24h = $this->gameResults->playCountsLast24HoursByChallengeSetIds($setIds);

        $rows = [];
        foreach ($sets as $set) {
            $id = (int) $set->getId();
            $row = $this->mapChallengeListRow(
                $set,
                $roundCounts[$id] ?? 0,
                $playCounts[$id] ?? 0,
                $plays24h[$id] ?? 0,
                false
            );
            $row['published'] = $set->isPublished();
            $row['author_user_id'] = $set->getAuthorUserId();
            $rows[] = $row;
        }

        $this->sortChallengeListRows($rows, (string) ($filters['sort'] ?? 'newest'));

        return array_slice($rows, 0, 200);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findChallengeSet(int $id, ?int $userId = null): ?array
    {
        $set = $this->createQueryBuilder('cs')
            ->select('cs', 'r')
            ->join('cs.region', 'r')
            ->where('cs.id = :id')
            ->andWhere('cs.published = true')
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$set instanceof ChallengeSet) {
            return null;
        }

        $region = $set->getRegion();
        $liked = false;
        if ($userId !== null) {
            $liked = (int) $this->getEntityManager()->createQueryBuilder()
                ->select('COUNT(vl.id)')
                ->from(ChallengeLike::class, 'vl')
                ->where('vl.challengeSet = :set')
                ->andWhere('vl.userId = :userId')
                ->setParameter('set', $set)
                ->setParameter('userId', $userId)
                ->getQuery()
                ->getSingleScalarResult() > 0;
        }

        $setId = (int) $set->getId();
        $playCounts = $this->gameResults->playCountsByChallengeSetIds([$setId]);
        $plays24h = $this->gameResults->playCountsLast24HoursByChallengeSetIds([$setId]);

        $row = [
            'id' => $set->getId(),
            'title' => $set->getTitle(),
            'description' => $set->getDescription(),
            'author_user_id' => $set->getAuthorUserId(),
            'author_name' => $set->getAuthorName(),
            'play_count' => $playCounts[$setId] ?? 0,
            'plays_24h' => $plays24h[$setId] ?? 0,
            'like_count' => $set->getLikeCount(),
            'comment_count' => $set->getCommentCount(),
            'region_id' => $region->getId(),
            'region_name' => $region->getName(),
            'region_slug' => $region->getSlug(),
            'center_lat' => $region->getCenterLat(),
            'center_lng' => $region->getCenterLng(),
            'sw_lat' => $region->getSwLat(),
            'sw_lng' => $region->getSwLng(),
            'ne_lat' => $region->getNeLat(),
            'ne_lng' => $region->getNeLng(),
            'liked_by_viewer' => $liked,
        ];

        $row['locations'] = $this->locationsForChallengeSet($set);

        $row['comments'] = $this->getEntityManager()->createQueryBuilder()
            ->select('c.id, c.authorName AS author_name, c.body')
            ->from(ChallengeComment::class, 'c')
            ->where('c.challengeSet = :set')
            ->setParameter('set', $set)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getArrayResult();

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findChallengeSetForAdmin(int $id): ?array
    {
        $set = $this->createQueryBuilder('cs')
            ->select('cs', 'r')
            ->join('cs.region', 'r')
            ->where('cs.id = :id')
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$set instanceof ChallengeSet) {
            return null;
        }

        $region = $set->getRegion();
        $setId = (int) $set->getId();
        $playCounts = $this->gameResults->playCountsByChallengeSetIds([$setId]);

        $row = [
            'id' => $set->getId(),
            'title' => $set->getTitle(),
            'description' => $set->getDescription(),
            'author_user_id' => $set->getAuthorUserId(),
            'author_name' => $set->getAuthorName(),
            'play_count' => $playCounts[$setId] ?? 0,
            'like_count' => $set->getLikeCount(),
            'comment_count' => $set->getCommentCount(),
            'published' => $set->isPublished(),
            'region_id' => $region->getId(),
            'region_name' => $region->getName(),
            'region_slug' => $region->getSlug(),
            'center_lat' => $region->getCenterLat(),
            'center_lng' => $region->getCenterLng(),
            'sw_lat' => $region->getSwLat(),
            'sw_lng' => $region->getSwLng(),
            'ne_lat' => $region->getNeLat(),
            'ne_lng' => $region->getNeLng(),
        ];

        $row['locations'] = $this->locationsForChallengeSet($set);

        $row['comments'] = $this->getEntityManager()->createQueryBuilder()
            ->select('c.id, c.authorName AS author_name, c.body')
            ->from(ChallengeComment::class, 'c')
            ->where('c.challengeSet = :set')
            ->setParameter('set', $set)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getArrayResult();

        return $row;
    }

    public function createChallengeSet(
        string $title,
        ?string $description,
        int $regionId,
        string $authorName,
        int $authorUserId
    ): int {
        $region = $this->regions->find($regionId);
        if (!$region instanceof Region) {
            throw new \InvalidArgumentException('Region not found.');
        }

        $set = (new ChallengeSet())
            ->setTitle($title)
            ->setDescription($description)
            ->setRegion($region)
            ->setAuthorUserId($authorUserId)
            ->setAuthorName($authorName);

        $em = $this->getEntityManager();
        $em->persist($set);
        $em->flush();

        return (int) $set->getId();
    }

    public function attachLocation(int $challengeSetId, int $locationId, int $position): void
    {
        $em = $this->getEntityManager();
        $set = $this->find($challengeSetId);
        $location = $em->find(Location::class, $locationId);
        if (!$set instanceof ChallengeSet || !$location instanceof Location) {
            throw new \InvalidArgumentException('Challenge set or location not found.');
        }

        $link = (new ChallengeSetLocation())
            ->setChallengeSet($set)
            ->setLocation($location)
            ->setPosition($position);

        $set->addSetLocation($link);
        $em->persist($link);
        $em->flush();
    }

    public function isOwnedByUser(int $challengeSetId, int $authorUserId): bool
    {
        return $this->findOwnedChallengeSet($challengeSetId, $authorUserId) instanceof ChallengeSet;
    }

    public function countLocations(int $challengeSetId): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(csl.location)')
            ->from(ChallengeSetLocation::class, 'csl')
            ->where('csl.challengeSet = :id')
            ->setParameter('id', $challengeSetId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteOwnedChallengeSet(int $challengeSetId, int $authorUserId): bool
    {
        $set = $this->findOwnedChallengeSet($challengeSetId, $authorUserId);
        if (!$set instanceof ChallengeSet) {
            return false;
        }

        return $this->deleteChallengeSet($set);
    }

    public function deleteChallengeSetForAdmin(int $challengeSetId): bool
    {
        $set = $this->find($challengeSetId);
        if (!$set instanceof ChallengeSet) {
            return false;
        }

        return $this->deleteChallengeSet($set);
    }

    public function setChallengeSetPublishedForAdmin(int $challengeSetId, bool $published): bool
    {
        $set = $this->find($challengeSetId);
        if (!$set instanceof ChallengeSet) {
            return false;
        }

        $set->setPublished($published);
        $this->getEntityManager()->flush();

        return true;
    }

    public function deleteCommentForAdmin(int $commentId): bool
    {
        $em = $this->getEntityManager();
        $comment = $em->find(ChallengeComment::class, $commentId);
        if (!$comment instanceof ChallengeComment) {
            return false;
        }

        $comment->getChallengeSet()->decrementCommentCount();
        $em->remove($comment);
        $em->flush();

        return true;
    }

    public function updateOwnedChallengeSet(
        int $challengeSetId,
        int $authorUserId,
        string $title,
        ?string $description,
        ?int $regionId
    ): bool {
        $set = $this->findOwnedChallengeSet($challengeSetId, $authorUserId);
        if (!$set instanceof ChallengeSet) {
            return false;
        }

        $previousRegion = $set->getRegion();
        $set->setTitle($title)->setDescription($description);
        if ($regionId !== null) {
            $region = $this->regions->find($regionId);
            if (!$region instanceof Region) {
                return false;
            }
            $set->setRegion($region);
        }

        $this->getEntityManager()->flush();
        if ($set->getRegion()->getId() !== $previousRegion->getId()) {
            $this->deleteUnusedCustomRegion($previousRegion);
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMine(int $authorUserId): array
    {
        $sets = $this->createQueryBuilder('cs')
            ->select('cs', 'r')
            ->join('cs.region', 'r')
            ->where('cs.authorUserId = :authorUserId')
            ->setParameter('authorUserId', $authorUserId)
            ->orderBy('cs.id', 'DESC')
            ->getQuery()
            ->getResult();

        $setIds = array_map(static fn (ChallengeSet $set): int => (int) $set->getId(), $sets);
        $playCounts = $this->gameResults->playCountsByChallengeSetIds($setIds);

        $rows = [];
        foreach ($sets as $set) {
            if (!$set instanceof ChallengeSet) {
                continue;
            }
            $id = (int) $set->getId();
            $rows[] = [
                'id' => $set->getId(),
                'title' => $set->getTitle(),
                'description' => $set->getDescription(),
                'author_user_id' => $set->getAuthorUserId(),
                'author_name' => $set->getAuthorName(),
                'play_count' => $playCounts[$id] ?? 0,
                'like_count' => $set->getLikeCount(),
                'comment_count' => $set->getCommentCount(),
                'region_name' => $set->getRegion()->getName(),
                'round_count' => $this->countLocations($id),
            ];
        }

        return $rows;
    }

    public function deleteOwnedChallengeLocation(
        int $challengeSetId,
        int $locationId,
        int $authorUserId
    ): bool {
        $set = $this->findOwnedChallengeSet($challengeSetId, $authorUserId);
        if (!$set instanceof ChallengeSet || $this->countLocations($challengeSetId) <= 1) {
            return false;
        }

        $em = $this->getEntityManager();
        $link = $em->createQueryBuilder()
            ->select('csl', 'l')
            ->from(ChallengeSetLocation::class, 'csl')
            ->join('csl.location', 'l')
            ->where('csl.challengeSet = :set')
            ->andWhere('l.id = :locationId')
            ->setParameter('set', $set)
            ->setParameter('locationId', $locationId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$link instanceof ChallengeSetLocation) {
            return false;
        }

        $em->remove($link);
        $em->flush();
        $this->refreshChallengeRegionFromLocations($set);

        return true;
    }

    public function refreshOwnedChallengeRegionFromLocations(
        int $challengeSetId,
        int $authorUserId,
        ?string $regionName = null
    ): bool {
        $set = $this->findOwnedChallengeSet($challengeSetId, $authorUserId);
        if (!$set instanceof ChallengeSet) {
            return false;
        }

        $this->refreshChallengeRegionFromLocations($set, $regionName);

        return true;
    }

    /**
     * @return list<array{id: int, lat: float, lng: float}>
     */
    public function coordinatePointsForOwnedChallengeSet(
        int $challengeSetId,
        int $authorUserId
    ): array {
        $set = $this->findOwnedChallengeSet($challengeSetId, $authorUserId);

        return $set instanceof ChallengeSet ? $this->coordinatePointsForChallengeSet($set) : [];
    }

    /**
     * @param list<int> $excludeIds
     *
     * @return array<string, mixed>|null
     */
    public function findRandomLocationForSet(int $challengeSetId, array $excludeIds = [], bool $allowReset = true): ?array
    {
        $excludeIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $excludeIds),
            static fn (int $id): bool => $id > 0
        ));

        $row = $this->pickRandomLocationRowForSet($challengeSetId, $excludeIds);
        if ($row === null && $allowReset && $excludeIds !== []) {
            return $this->findRandomLocationForSet($challengeSetId, []);
        }

        return $row;
    }

    public function recordChallengeSetPlay(int $challengeSetId): void
    {
        // Play counts are derived from completed GameResults rows.
    }

    /**
     * @return array{liked: bool, like_count: int}
     */
    public function toggleLike(int $challengeSetId, int $userId): array
    {
        $em = $this->getEntityManager();
        $set = $this->find($challengeSetId);
        if (!$set instanceof ChallengeSet) {
            return ['liked' => false, 'like_count' => 0];
        }

        $existing = $em->getRepository(ChallengeLike::class)->findOneBy([
            'challengeSet' => $set,
            'userId' => $userId,
        ]);

        if ($existing instanceof ChallengeLike) {
            $em->remove($existing);
            $liked = false;
        } else {
            $em->persist((new ChallengeLike())->setChallengeSet($set)->setUserId($userId));
            $liked = true;
        }

        $em->flush();

        $count = (int) $em->createQueryBuilder()
            ->select('COUNT(cl.id)')
            ->from(ChallengeLike::class, 'cl')
            ->where('cl.challengeSet = :set')
            ->setParameter('set', $set)
            ->getQuery()
            ->getSingleScalarResult();

        $set->setLikeCount($count);
        $em->flush();

        return ['liked' => $liked, 'like_count' => $count];
    }

    public function addComment(int $challengeSetId, int $userId, string $authorName, string $body): void
    {
        $em = $this->getEntityManager();
        $set = $this->find($challengeSetId);
        if (!$set instanceof ChallengeSet) {
            return;
        }

        $comment = (new ChallengeComment())
            ->setChallengeSet($set)
            ->setUserId($userId)
            ->setAuthorName($authorName)
            ->setBody($body);

        $set->incrementCommentCount();
        $em->persist($comment);
        $em->flush();
    }

    /**
     * @return list<array{hour: string, plays: int}>
     */
    public function hourlyStats(int $challengeSetId): array
    {
        if (!$this->find($challengeSetId) instanceof ChallengeSet) {
            return [];
        }

        return $this->gameResults->hourlyPlayCountsForChallengeSet($challengeSetId);
    }

    /**
     * @param list<int> $setIds
     *
     * @return array<int, int>
     */
    private function batchRoundCounts(array $setIds): array
    {
        /** @var list<array{setId: int, cnt: string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(csl.challengeSet) AS setId', 'COUNT(csl.location) AS cnt')
            ->from(ChallengeSetLocation::class, 'csl')
            ->where('csl.challengeSet IN (:ids)')
            ->setParameter('ids', $setIds)
            ->groupBy('csl.challengeSet')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['setId']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * @param list<int> $setIds
     *
     * @return array<int, true>
     */
    private function likedSetIdsForUser(array $setIds, ?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        /** @var list<array{setId: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(cl.challengeSet) AS setId')
            ->from(ChallengeLike::class, 'cl')
            ->where('cl.challengeSet IN (:ids)')
            ->andWhere('cl.userId = :userId')
            ->setParameter('ids', $setIds)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        $liked = [];
        foreach ($rows as $row) {
            $liked[(int) $row['setId']] = true;
        }

        return $liked;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapChallengeListRow(
        ChallengeSet $set,
        int $roundCount,
        int $playCount,
        int $plays24h,
        bool $likedByViewer
    ): array {
        $region = $set->getRegion();

        return [
            'id' => $set->getId(),
            'title' => $set->getTitle(),
            'description' => $set->getDescription(),
            'author_name' => $set->getAuthorName(),
            'play_count' => $playCount,
            'plays_24h' => $plays24h,
            'like_count' => $set->getLikeCount(),
            'comment_count' => $set->getCommentCount(),
            'region_name' => $region->getName(),
            'region_slug' => $region->getSlug(),
            'round_count' => $roundCount,
            'liked_by_viewer' => $likedByViewer,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function sortChallengeListRows(array &$rows, string $sort): void
    {
        usort($rows, static function (array $a, array $b) use ($sort): int {
            $idA = (int) ($a['id'] ?? 0);
            $idB = (int) ($b['id'] ?? 0);

            return match ($sort) {
                'newest' => $idB <=> $idA,
                'most_played' => ($b['play_count'] <=> $a['play_count']) ?: ($idB <=> $idA),
                'most_liked' => ($b['like_count'] <=> $a['like_count']) ?: ($idB <=> $idA),
                'trending' => ($b['plays_24h'] <=> $a['plays_24h'])
                    ?: ($b['like_count'] <=> $a['like_count'])
                    ?: ($idB <=> $idA),
                'popular' => self::popularityScore($b) <=> self::popularityScore($a) ?: ($idB <=> $idA),
                default => self::recommendedScore($b) <=> self::recommendedScore($a) ?: ($idB <=> $idA),
            };
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function popularityScore(array $row): int
    {
        return (int) $row['play_count']
            + (int) $row['like_count'] * 3
            + (int) $row['comment_count'] * 2;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function recommendedScore(array $row): int
    {
        $likePenalty = !empty($row['liked_by_viewer']) ? -20 : 0;

        return $likePenalty
            + (int) $row['plays_24h'] * 4
            + (int) $row['like_count'] * 3
            + (int) $row['play_count']
            + (int) $row['comment_count'] * 2;
    }

    private function findOwnedChallengeSet(int $challengeSetId, int $authorUserId): ?ChallengeSet
    {
        $set = $this->createQueryBuilder('cs')
            ->where('cs.id = :id')
            ->andWhere('cs.authorUserId = :authorUserId')
            ->setParameter('id', $challengeSetId)
            ->setParameter('authorUserId', $authorUserId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $set instanceof ChallengeSet ? $set : null;
    }

    private function deleteChallengeSet(ChallengeSet $set): bool
    {
        $em = $this->getEntityManager();
        $region = $set->getRegion();
        $locationIds = [];
        foreach ($set->getSetLocations() as $link) {
            $locationIds[] = (int) $link->getLocation()->getId();
        }

        $em->remove($set);
        $em->flush();

        if ($locationIds !== []) {
            $em->createQuery('DELETE FROM App\Entity\Location l WHERE l.id IN (:ids)')
                ->setParameter('ids', $locationIds)
                ->execute();
        }
        $this->deleteUnusedCustomRegion($region);

        return true;
    }

    private function deleteUnusedCustomRegion(Region $region): void
    {
        $seedSlugs = ['leuven', 'brussels', 'belgium', 'europe', 'world'];
        if (in_array($region->getSlug(), $seedSlugs, true)) {
            return;
        }

        $em = $this->getEntityManager();
        $usageCount = (int) $em->createQueryBuilder()
            ->select('COUNT(cs.id)')
            ->from(ChallengeSet::class, 'cs')
            ->where('cs.region = :region')
            ->setParameter('region', $region)
            ->getQuery()
            ->getSingleScalarResult();

        if ($usageCount === 0) {
            $em->remove($region);
            $em->flush();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function locationsForChallengeSet(ChallengeSet $set): array
    {
        /** @var list<ChallengeSetLocation> $links */
        $links = $this->getEntityManager()->createQueryBuilder()
            ->select('csl', 'l')
            ->from(ChallengeSetLocation::class, 'csl')
            ->join('csl.location', 'l')
            ->where('csl.challengeSet = :set')
            ->setParameter('set', $set)
            ->orderBy('csl.position', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        $locationIds = [];
        foreach ($links as $link) {
            $locationIds[] = (int) $link->getLocation()->getId();
        }

        $latLngById = $this->locations->findLatLngByIds($locationIds);
        $locations = [];
        foreach ($links as $link) {
            $location = $link->getLocation();
            $locationId = (int) $location->getId();
            $coords = $latLngById[$locationId] ?? null;
            if ($coords === null) {
                continue;
            }

            $locations[] = [
                'id' => $locationId,
                'photo_source' => $location->getPhotoSource(),
                'position' => $link->getPosition(),
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
            ];
        }

        return $locations;
    }

    /**
     * @return list<array{id: int, lat: float, lng: float}>
     */
    private function coordinatePointsForChallengeSet(ChallengeSet $set): array
    {
        $locations = $this->locationsForChallengeSet($set);

        return array_values(array_filter(array_map(static function (array $location): ?array {
            $lat = $location['lat'] ?? null;
            $lng = $location['lng'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng)) {
                return null;
            }

            return [
                'id' => (int) $location['id'],
                'lat' => (float) $lat,
                'lng' => (float) $lng,
            ];
        }, $locations)));
    }

    private function refreshChallengeRegionFromLocations(ChallengeSet $set, ?string $regionName = null): void
    {
        $points = $this->coordinatePointsForChallengeSet($set);
        if ($points === []) {
            return;
        }

        $bounds = self::aggregateBoundsForPoints($points);
        $name = trim((string) ($regionName ?? $set->getRegion()->getName()));
        if ($name === '') {
            $name = sprintf('%.4F, %.4F', $bounds['center_lat'], $bounds['center_lng']);
        }

        $previousRegion = $set->getRegion();
        $regionId = $this->findOrCreateRegion(
            $name,
            $bounds['center_lat'],
            $bounds['center_lng'],
            $bounds['sw_lat'],
            $bounds['sw_lng'],
            $bounds['ne_lat'],
            $bounds['ne_lng']
        );
        $region = $this->regions->find($regionId);
        if ($region instanceof Region) {
            $set->setRegion($region);
            $this->getEntityManager()->flush();
            if ($region->getId() !== $previousRegion->getId()) {
                $this->deleteUnusedCustomRegion($previousRegion);
            }
        }
    }

    /**
     * @param list<array{id?: int, lat: float, lng: float}> $points
     *
     * @return array{center_lat: float, center_lng: float, sw_lat: float, sw_lng: float, ne_lat: float, ne_lng: float}
     */
    private static function aggregateBoundsForPoints(array $points): array
    {
        $lats = array_map(static fn (array $point): float => (float) $point['lat'], $points);
        $lngs = array_map(static fn (array $point): float => (float) $point['lng'], $points);

        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);
        $centerLat = array_sum($lats) / count($lats);
        $centerLng = array_sum($lngs) / count($lngs);

        $latSpan = max(0.02, $maxLat - $minLat);
        $lngSpan = max(0.02, $maxLng - $minLng);
        $latPadding = max(0.01, $latSpan * 0.15);
        $lngPadding = max(0.01, $lngSpan * 0.15);

        return [
            'center_lat' => self::clamp($centerLat, -85.0, 85.0),
            'center_lng' => self::clamp($centerLng, -180.0, 180.0),
            'sw_lat' => self::clamp($minLat - $latPadding, -85.0, 85.0),
            'sw_lng' => self::clamp($minLng - $lngPadding, -180.0, 180.0),
            'ne_lat' => self::clamp($maxLat + $latPadding, -85.0, 85.0),
            'ne_lng' => self::clamp($maxLng + $lngPadding, -180.0, 180.0),
        ];
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * @param list<int> $excludeIds
     *
     * @return array<string, mixed>|null
     */
    private function pickRandomLocationRowForSet(int $challengeSetId, array $excludeIds): ?array
    {
        $set = $this->find($challengeSetId);
        if (!$set instanceof ChallengeSet) {
            return null;
        }

        /** @var list<ChallengeSetLocation> $links */
        $links = $this->getEntityManager()->createQueryBuilder()
            ->select('csl', 'l')
            ->from(ChallengeSetLocation::class, 'csl')
            ->join('csl.location', 'l')
            ->where('csl.challengeSet = :set')
            ->setParameter('set', $set)
            ->getQuery()
            ->getResult();

        $candidates = [];
        $candidateIds = [];
        foreach ($links as $link) {
            $location = $link->getLocation();
            $locationId = (int) $location->getId();
            if (in_array($locationId, $excludeIds, true)) {
                continue;
            }
            $candidateIds[] = $locationId;

            $candidates[] = [
                'id' => $locationId,
                'photo_source' => $location->getPhotoSource(),
                'has_stored_photo' => false,
                'challenge_title' => $set->getTitle(),
                'region_name' => $set->getRegion()->getName(),
                'center_lat' => $set->getRegion()->getCenterLat(),
                'center_lng' => $set->getRegion()->getCenterLng(),
                'sw_lat' => $set->getRegion()->getSwLat(),
                'sw_lng' => $set->getRegion()->getSwLng(),
                'ne_lat' => $set->getRegion()->getNeLat(),
                'ne_lng' => $set->getRegion()->getNeLng(),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        $storedPhotoIds = $this->locations->idsWithStoredPhoto($candidateIds);
        foreach ($candidates as &$candidate) {
            $candidate['has_stored_photo'] = isset($storedPhotoIds[(int) $candidate['id']]);
        }
        unset($candidate);

        return $candidates[array_rand($candidates)];
    }
}
