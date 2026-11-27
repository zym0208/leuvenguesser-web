<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChallengeSet;
use App\Entity\ChallengeSetLocation;
use App\Entity\Region;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Region>
 */
class RegionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Region::class);
    }

    /**
     * Same ordering and fields as the legacy {@see ChallengeRepository::listRegions} query.
     *
     * @return list<array<string, mixed>>
     */
    public function listRegionsAssociative(): array
    {
        $regions = $this->createQueryBuilder('r')
            ->innerJoin(ChallengeSet::class, 'cs', 'WITH', 'cs.region = r')
            ->groupBy('r.id')
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        $rows = [];
        foreach ($regions as $region) {
            if ($region instanceof Region) {
                $rows[] = $region->toListRow();
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRegionRowById(int $id): ?array
    {
        $region = $this->find($id);

        return $region instanceof Region ? $region->toListRow() : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRegionRowBySlug(string $slug): ?array
    {
        $region = $this->findOneBy(['slug' => $slug]);

        return $region instanceof Region ? $region->toListRow() : null;
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
        $name = trim($name);
        if ($name === '') {
            $name = sprintf('%.4F, %.4F', $centerLat, $centerLng);
        }

        $slug = $this->slugify($name);
        $existing = $this->findOneBy(['slug' => $slug]);
        if ($existing instanceof Region) {
            return (int) $existing->getId();
        }

        $deltaLat = 1.0;
        $deltaLng = 1.0;
        $swLat ??= max(-85.0, $centerLat - $deltaLat);
        $swLng ??= max(-180.0, $centerLng - $deltaLng);
        $neLat ??= min(85.0, $centerLat + $deltaLat);
        $neLng ??= min(180.0, $centerLng + $deltaLng);

        $region = (new Region())
            ->setName(mb_substr($name, 0, 90))
            ->setSlug(mb_substr($slug, 0, 90))
            ->setCenterLat($centerLat)
            ->setCenterLng($centerLng)
            ->setSwLat(min($swLat, $neLat))
            ->setSwLng(min($swLng, $neLng))
            ->setNeLat(max($swLat, $neLat))
            ->setNeLng(max($swLng, $neLng));

        $em = $this->getEntityManager();
        $em->persist($region);
        $em->flush();

        return (int) $region->getId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRegionRowForChallengeLocation(int $challengeSetId, int $locationId): ?array
    {
        $row = $this->getEntityManager()->createQueryBuilder()
            ->select('r')
            ->from(Region::class, 'r')
            ->join(ChallengeSet::class, 'cs', 'WITH', 'cs.region = r')
            ->join(ChallengeSetLocation::class, 'csl', 'WITH', 'csl.challengeSet = cs')
            ->join('csl.location', 'loc')
            ->where('cs.id = :setId')
            ->andWhere('loc.id = :locationId')
            ->andWhere('cs.published = true')
            ->setParameter('setId', $challengeSetId)
            ->setParameter('locationId', $locationId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row instanceof Region ? $row->toListRow() : null;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'region-' . substr(sha1($name), 0, 12);
        }

        return $slug;
    }
}
