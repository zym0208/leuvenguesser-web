<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Location;
use App\Geo\GeoPoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Location>
 */
class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    /**
     * @param list<int> $excludeIds
     *
     * @return array{id: int, photo_source: string, has_stored_photo: bool}|null
     */
    public function findRandomForRoundExcluding(array $excludeIds): ?array
    {
        return $this->findRandomAssociative($excludeIds, null);
    }

    /**
     * @param list<int> $excludeIds
     *
     * @return array{id: int, photo_source: string, has_stored_photo: bool}|null
     */
    public function findRandomForRegionRoundExcluding(string $regionSlug, array $excludeIds): ?array
    {
        $row = $this->findRandomAssociative($excludeIds, $regionSlug);
        if ($row === null && $excludeIds !== []) {
            return $this->findRandomForRegionRoundExcluding($regionSlug, []);
        }

        return $row;
    }

    /**
     * @param list<int> $excludeIds
     *
     * @return array{id: int, photo_source: string, has_stored_photo: bool}|null
     */
    /**
     * Native SQL: MySQL spatial bbox (ST_Latitude/ST_Longitude) and ORDER BY RAND() are not practical in DQL.
     */
    private function findRandomAssociative(array $excludeIds, ?string $regionSlug): ?array
    {
        $excludeIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $excludeIds),
            static fn (int $id): bool => $id > 0
        ));

        $conn = $this->getEntityManager()->getConnection();
        $params = [];
        $excludeSql = '';

        if ($excludeIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($excludeIds), '?'));
            $excludeSql = sprintf('AND l.id NOT IN (%s)', $placeholders);
            $params = $excludeIds;
        }

        if ($regionSlug !== null) {
            $sql = 'SELECT l.id, l.photo_source, l.photo_data IS NOT NULL AS has_stored_photo
                    FROM Locations l
                    JOIN Regions r ON r.slug = ?
                    WHERE ST_Latitude(l.coordinates) BETWEEN r.sw_lat AND r.ne_lat
                      AND ST_Longitude(l.coordinates) BETWEEN r.sw_lng AND r.ne_lng
                      ' . $excludeSql . '
                    ORDER BY RAND() LIMIT 1';
            array_unshift($params, $regionSlug);
        } else {
            $sql = 'SELECT id, photo_source, photo_data IS NOT NULL AS has_stored_photo
                    FROM Locations l
                    WHERE 1=1 ' . $excludeSql . ' ORDER BY RAND() LIMIT 1';
        }

        $row = $conn->fetchAssociative($sql, $params);
        if ($row === false && $excludeIds !== []) {
            return $this->findRandomAssociative([], $regionSlug);
        }

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'photo_source' => (string) $row['photo_source'],
            'has_stored_photo' => (bool) $row['has_stored_photo'],
        ];
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function findLatLngById(int $id): ?array
    {
        $all = $this->findLatLngByIds([$id]);

        return $all[$id] ?? null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    public function findLatLngByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $out = [];
        $missing = [];

        $locations = $this->createQueryBuilder('l')
            ->where('l.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        foreach ($locations as $location) {
            if (!$location instanceof Location) {
                continue;
            }
            $locationId = (int) $location->getId();
            $point = $location->getCoordinates();
            if ($point instanceof GeoPoint) {
                $out[$locationId] = ['lat' => $point->lat, 'lng' => $point->lng];
            } else {
                $missing[] = $locationId;
            }
        }

        foreach ($ids as $id) {
            if (!isset($out[$id]) && !in_array($id, $missing, true)) {
                $missing[] = $id;
            }
        }

        if ($missing === []) {
            return $out;
        }

        $conn = $this->getEntityManager()->getConnection();
        $placeholders = implode(', ', array_fill(0, count($missing), '?'));
        $rows = $conn->fetchAllAssociative(
            sprintf(
                'SELECT id, ST_Latitude(coordinates) AS lat, ST_Longitude(coordinates) AS lng
                 FROM Locations WHERE id IN (%s)',
                $placeholders
            ),
            $missing
        );

        foreach ($rows as $row) {
            if ($row['lat'] === null || $row['lng'] === null) {
                continue;
            }
            $out[(int) $row['id']] = ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng']];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, true>
     */
    public function idsWithStoredPhoto(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.id')
            ->where('l.id IN (:ids)')
            ->andWhere('l.photoData IS NOT NULL')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = true;
        }

        return $out;
    }

    public function createUploadedLocation(
        float $lat,
        float $lng,
        string $photoSource,
        string $mimeType,
        string $photoData
    ): int {
        $location = (new Location())
            ->setCoordinates(new GeoPoint($lat, $lng))
            ->setPhotoSource($photoSource)
            ->setPhotoMimeType($mimeType)
            ->setPhotoData($photoData);

        $em = $this->getEntityManager();
        $em->persist($location);
        $em->flush();

        return (int) $location->getId();
    }

    /**
     * @return array{mime_type: string, data: string, photo_source: string}|null
     */
    public function findStoredPhotoById(int $id): ?array
    {
        $conn = $this->getEntityManager()->getConnection();
        $row = $conn->fetchAssociative(
            'SELECT photo_source, photo_data, photo_mime_type FROM Locations WHERE id = ? AND photo_data IS NOT NULL',
            [$id]
        );

        if ($row === false || $row === null) {
            return null;
        }

        $data = $row['photo_data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        if (!is_string($data) || $data === '') {
            return null;
        }

        $mime = $row['photo_mime_type'] ?? '';
        if ($mime === null || $mime === '') {
            return null;
        }

        return [
            'mime_type' => (string) $mime,
            'data' => $data,
            'photo_source' => (string) $row['photo_source'],
        ];
    }

    public function findLocationById(int $id): ?array
    {
        $location = $this->createQueryBuilder('l')
            ->select('l')
            ->where('l.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$location instanceof Location) {
            return null;
        }

        $hasStoredPhoto = (is_null($location->getPhotoData()))?false:true;

        return [
            'id' => $location->getId(),
            'photo_source' => $location->getPhotoSource(),
            'has_stored_photo' => $hasStoredPhoto
        ];
    }
}
