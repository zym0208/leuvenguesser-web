<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ChallengeRepository;
use App\Repository\LocationRepository;
use App\Repository\RegionRepository;
use App\Service\AuthSessionManager;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/challenges')]
final class ChallengeApiController extends AbstractController
{
    private const MAX_UPLOAD_BYTES = 6_000_000;
    private const MAX_IMAGES_PER_SET = 10;
    private const MAX_CHALLENGE_SET_DIAGONAL_METERS = 30000.0;
    private const SPREAD_ERROR = 'Images are too spread out. Split this into multiple challenge sets.';
    private const ALLOWED_IMAGE_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly ChallengeRepository $challenges,
        private readonly LocationRepository $locations,
        private readonly RegionRepository $regions,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSessionManager $authSession,
    ) {
    }

    #[Route('/regions', name: 'api_challenges_regions', methods: ['GET'])]
    public function regions(): JsonResponse
    {
        try {
            $response = $this->json(['success' => true, 'regions' => $this->regions->listRegionsAssociative()]);
        } catch (Exception $exception) {
            $response = $this->json([
                'success' => false,
                'error' => 'Database error while loading regions.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $response;
    }

    #[Route('/regions/resolve', name: 'api_challenges_regions_resolve', methods: ['POST'])]
    public function resolveRegion(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $lat = $payload['lat'] ?? null;
        $lng = $payload['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return $this->json(['success' => false, 'error' => 'Missing or invalid anchor coordinates.'], Response::HTTP_BAD_REQUEST);
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return $this->json(['success' => false, 'error' => 'Anchor coordinates out of range.'], Response::HTTP_BAD_REQUEST);
        }

        $name = $this->normalizeText($payload['name'] ?? null, 90) ?? sprintf('%.4F, %.4F', $lat, $lng);
        $bounds = is_array($payload['bounds'] ?? null) ? $payload['bounds'] : [];

        $swLat = $this->numericOrNull($bounds['swLat'] ?? null);
        $swLng = $this->numericOrNull($bounds['swLng'] ?? null);
        $neLat = $this->numericOrNull($bounds['neLat'] ?? null);
        $neLng = $this->numericOrNull($bounds['neLng'] ?? null);

        $region = [
            'id' => null,
            'name' => $name,
            'center_lat' => $lat,
            'center_lng' => $lng,
            'sw_lat' => $swLat ?? max(-85.0, $lat - 1.0),
            'sw_lng' => $swLng ?? max(-180.0, $lng - 1.0),
            'ne_lat' => $neLat ?? min(85.0, $lat + 1.0),
            'ne_lng' => $neLng ?? min(180.0, $lng + 1.0),
        ];

        return $this->json(['success' => true, 'region' => $region]);
    }

    #[Route('', name: 'api_challenges_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filters = [
            'sort' => $request->query->getString('sort', 'recommended'),
            'q' => trim($request->query->getString('q', '')),
            'author' => trim($request->query->getString('author', '')),
            'region' => trim($request->query->getString('region', '')),
        ];
        $userId = $this->authSession->currentUser($request)?->getId();

        try {
            return $this->json([
                'success' => true,
                'challenges' => $this->challenges->listChallengeSets($filters, $userId),
            ]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while loading challenge sets.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/mine', name: 'api_challenges_mine', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Login required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            return $this->json([
                'success' => true,
                'challenges' => $this->challenges->listMine((int) $user->getId()),
            ]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while loading creator challenges.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/{id}', name: 'api_challenges_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, Request $request): JsonResponse
    {
        $userId = $this->authSession->currentUser($request)?->getId();

        try {
            $challenge = $this->challenges->findChallengeSet($id, $userId);
            if ($challenge === null) {
                return $this->json(['success' => false, 'error' => 'Challenge set not found.'], Response::HTTP_NOT_FOUND);
            }

            return $this->json(['success' => true, 'challenge' => $challenge]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while loading challenge set.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('', name: 'api_challenges_publish', methods: ['POST'])]
    public function publish(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Login required.'], Response::HTTP_UNAUTHORIZED);
        }

        $authorUserId = (int) $user->getId();
        $title = $this->normalizeText($request->request->get('title'), 120);
        $description = $this->normalizeText($request->request->get('description'), 1200);
        $authorName = $this->normalizeText($request->request->get('authorName'), 90) ?? $user->getName();
        $regionId = $request->request->get('regionId');
        $regionName = $this->normalizeText($request->request->get('regionName'), 90);
        $regionLat = $request->request->get('regionLat');
        $regionLng = $request->request->get('regionLng');

        if ($title === null) {
            return $this->json([
                'success' => false,
                'error' => 'Title is required.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $files = $this->uploadedImages($request);
        $lats = $this->requestArray($request, 'lat', 'lats');
        $lngs = $this->requestArray($request, 'lng', 'lngs');

        if ($files === [] || count($files) > self::MAX_IMAGES_PER_SET || count($files) !== count($lats) || count($files) !== count($lngs)) {
            return $this->json([
                'success' => false,
                'error' => sprintf('Upload 1-%d images and provide one coordinate pair per image.', self::MAX_IMAGES_PER_SET),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $points = $this->pointsFromLatLngArrays($lats, $lngs);
            $this->assertChallengeSetSpreadAllowed($points);

            if (is_numeric($regionId)) {
                $regionId = (int) $regionId;
                if ($this->challenges->findRegionById($regionId) === null) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Unknown region.',
                    ], Response::HTTP_BAD_REQUEST);
                }
            } elseif ($regionName !== null && is_numeric($regionLat) && is_numeric($regionLng)) {
                $regionId = $this->challenges->findOrCreateRegion(
                    $regionName,
                    (float) $regionLat,
                    (float) $regionLng,
                    $this->numericOrNull($request->request->get('regionSwLat')),
                    $this->numericOrNull($request->request->get('regionSwLng')),
                    $this->numericOrNull($request->request->get('regionNeLat')),
                    $this->numericOrNull($request->request->get('regionNeLng'))
                );
            } else {
                return $this->json([
                    'success' => false,
                    'error' => 'Choose answer points so the region can be generated.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $challengeSetId = $this->entityManager->wrapInTransaction(function () use (
                $title,
                $description,
                $regionId,
                $authorName,
                $authorUserId,
                $files,
                $lats,
                $lngs
            ): int {
                $id = $this->challenges->createChallengeSet($title, $description, $regionId, $authorName, $authorUserId);

                foreach ($files as $index => $image) {
                    $lat = $lats[$index] ?? null;
                    $lng = $lngs[$index] ?? null;
                    if (!is_numeric($lat) || !is_numeric($lng)) {
                        throw new \InvalidArgumentException('Invalid coordinates.');
                    }

                    $lat = (float) $lat;
                    $lng = (float) $lng;
                    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                        throw new \InvalidArgumentException('Coordinates out of range.');
                    }

                    [$mimeType, $photoData] = $this->readUploadedImage($image);
                    $photoSource = sprintf(
                        'challenge_%s_%s_%02d.%s',
                        (new \DateTimeImmutable())->format('Ymd_His'),
                        bin2hex(random_bytes(5)),
                        $index + 1,
                        self::ALLOWED_IMAGE_MIME_TYPES[$mimeType]
                    );

                    $locationId = $this->locations->createUploadedLocation(
                        $lat,
                        $lng,
                        $photoSource,
                        $mimeType,
                        $photoData
                    );
                    $this->challenges->attachLocation($id, $locationId, $index + 1);
                }

                return $id;
            });

            return $this->json([
                'success' => true,
                'id' => $challengeSetId,
            ], Response::HTTP_CREATED);
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();

            return $this->json([
                'success' => false,
                'error' => $message !== '' ? $message : 'Unable to publish challenge set.',
                'details' => $message,
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'api_challenges_update', methods: ['PATCH', 'POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Login required.'], Response::HTTP_UNAUTHORIZED);
        }

        $authorUserId = (int) $user->getId();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $title = $this->normalizeText($payload['title'] ?? null, 120);
        $description = $this->normalizeText($payload['description'] ?? null, 1200);
        $regionId = $payload['regionId'] ?? null;
        $regionName = $this->normalizeText($payload['regionName'] ?? null, 90);
        $regionLat = $payload['regionLat'] ?? null;
        $regionLng = $payload['regionLng'] ?? null;
        if ($title === null) {
            return $this->json(['success' => false, 'error' => 'Title is required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->assertChallengeSetSpreadAllowed($this->challenges->coordinatePointsForOwnedChallengeSet($id, $authorUserId));

            $nextRegionId = null;
            if (is_numeric($regionId)) {
                $nextRegionId = (int) $regionId;
                if ($this->challenges->findRegionById($nextRegionId) === null) {
                    return $this->json(['success' => false, 'error' => 'Unknown region.'], Response::HTTP_BAD_REQUEST);
                }
            } elseif ($regionName !== null && is_numeric($regionLat) && is_numeric($regionLng)) {
                $nextRegionId = $this->challenges->findOrCreateRegion(
                    $regionName,
                    (float) $regionLat,
                    (float) $regionLng,
                    $this->numericOrNull($payload['regionSwLat'] ?? null),
                    $this->numericOrNull($payload['regionSwLng'] ?? null),
                    $this->numericOrNull($payload['regionNeLat'] ?? null),
                    $this->numericOrNull($payload['regionNeLng'] ?? null)
                );
            }

            $updated = $this->challenges->updateOwnedChallengeSet(
                $id,
                $authorUserId,
                $title,
                $description,
                $nextRegionId
            );

            return $this->json(['success' => $updated]);
        } catch (\InvalidArgumentException $exception) {
            return $this->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while updating challenge set.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/{id}/locations', name: 'api_challenges_add_locations', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addLocations(int $id, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Login required.'], Response::HTTP_UNAUTHORIZED);
        }

        $authorUserId = (int) $user->getId();
        $regionName = $this->normalizeText($request->request->get('regionName'), 90);
        $files = $this->uploadedImages($request);
        $lats = $this->requestArray($request, 'lat', 'lats');
        $lngs = $this->requestArray($request, 'lng', 'lngs');

        if ($files === [] || count($files) !== count($lats) || count($files) !== count($lngs)) {
            return $this->json([
                'success' => false,
                'error' => 'Upload images and provide one coordinate pair per image.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            if (!$this->challenges->isOwnedByUser($id, $authorUserId)) {
                return $this->json([
                    'success' => false,
                    'error' => 'Challenge set not found for this user.',
                ], Response::HTTP_NOT_FOUND);
            }

            $currentCount = $this->challenges->countLocations($id);
            if ($currentCount + count($files) > self::MAX_IMAGES_PER_SET) {
                return $this->json([
                    'success' => false,
                    'error' => sprintf('A challenge set can contain at most %d images.', self::MAX_IMAGES_PER_SET),
                ], Response::HTTP_BAD_REQUEST);
            }

            $existingPoints = $this->challenges->coordinatePointsForOwnedChallengeSet($id, $authorUserId);
            $newPoints = $this->pointsFromLatLngArrays($lats, $lngs);
            $this->assertChallengeSetSpreadAllowed(array_merge($existingPoints, $newPoints));

            $this->entityManager->beginTransaction();
            foreach ($files as $index => $image) {
                $lat = $lats[$index] ?? null;
                $lng = $lngs[$index] ?? null;
                if (!is_numeric($lat) || !is_numeric($lng)) {
                    throw new \InvalidArgumentException('Invalid coordinates.');
                }

                $lat = (float) $lat;
                $lng = (float) $lng;
                if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                    throw new \InvalidArgumentException('Coordinates out of range.');
                }

                [$mimeType, $photoData] = $this->readUploadedImage($image);
                $photoSource = sprintf(
                    'challenge_%s_%s_%02d.%s',
                    (new \DateTimeImmutable())->format('Ymd_His'),
                    bin2hex(random_bytes(5)),
                    $currentCount + $index + 1,
                    self::ALLOWED_IMAGE_MIME_TYPES[$mimeType]
                );

                $locationId = $this->locations->createUploadedLocation(
                    $lat,
                    $lng,
                    $photoSource,
                    $mimeType,
                    $photoData
                );
                $this->challenges->attachLocation($id, $locationId, $currentCount + $index + 1);
            }
            $this->challenges->refreshOwnedChallengeRegionFromLocations(
                $id,
                $authorUserId,
                $regionName
            );

            $this->entityManager->commit();

            return $this->json([
                'success' => true,
                'added' => count($files),
                'round_count' => $currentCount + count($files),
            ], Response::HTTP_CREATED);
        } catch (\Throwable $exception) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            return $this->json([
                'success' => false,
                'error' => $exception instanceof \InvalidArgumentException && $exception->getMessage() !== '' ? $exception->getMessage() : 'Unable to add images.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'api_challenges_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[Route('/{id}/delete', name: 'api_challenges_delete_post', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Login required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $deleted = $this->challenges->deleteOwnedChallengeSet($id, (int) $user->getId());

            return $this->json(['success' => $deleted]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while deleting challenge set.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/{id}/locations/{locationId}', name: 'api_challenges_delete_location', methods: ['DELETE', 'POST'], requirements: ['id' => '\d+', 'locationId' => '\d+'])]
    public function deleteLocation(int $id, int $locationId, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Login required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $deleted = $this->challenges->deleteOwnedChallengeLocation($id, $locationId, (int) $user->getId());

            return $this->json([
                'success' => $deleted,
                'error' => $deleted ? null : 'Unable to delete this image. Keep at least one image in a challenge set.',
            ], $deleted ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while deleting image.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/{id}/like', name: 'api_challenges_like', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function like(int $id, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Login required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->challenges->toggleLike($id, (int) $user->getId());

            return $this->json(['success' => true] + $result);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while updating like.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/{id}/comments', name: 'api_challenges_comment', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function comment(int $id, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Login required.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $authorName = $this->normalizeText($payload['authorName'] ?? null, 90) ?? $user->getName();
        $body = $this->normalizeText($payload['body'] ?? null, 800);
        if ($body === null) {
            return $this->json(['success' => false, 'error' => 'Comment cannot be empty.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->challenges->addComment($id, (int) $user->getId(), $authorName, $body);

            return $this->json(['success' => true], Response::HTTP_CREATED);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while adding comment.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/{id}/stats', name: 'api_challenges_stats', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function stats(int $id): JsonResponse
    {
        try {
            return $this->json([
                'success' => true,
                'hourly' => $this->challenges->hourlyStats($id),
            ]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while loading stats.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function normalizeText(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<array{lat: float, lng: float}>
     */
    private function normalizePointPayload(mixed $points): array
    {
        if (!is_array($points)) {
            return [];
        }

        $normalized = [];
        foreach (array_slice(array_values($points), 0, self::MAX_IMAGES_PER_SET) as $point) {
            if (!is_array($point)) {
                continue;
            }
            $lat = $point['lat'] ?? null;
            $lng = $point['lng'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng)) {
                continue;
            }

            $lat = (float) $lat;
            $lng = (float) $lng;
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                continue;
            }

            $normalized[] = ['lat' => $lat, 'lng' => $lng];
        }

        return $normalized;
    }

    /**
     * @param list<mixed>|null $lats
     * @param list<mixed>|null $lngs
     *
     * @return list<array{lat: float, lng: float}>
     */
    private function pointsFromLatLngArrays(?array $lats, ?array $lngs): array
    {
        if ($lats === null || $lngs === null || count($lats) !== count($lngs)) {
            return [];
        }

        $points = [];
        foreach ($lats as $index => $lat) {
            $lng = $lngs[$index] ?? null;
            if (is_numeric($lat) && is_numeric($lng)) {
                $points[] = ['lat' => (float) $lat, 'lng' => (float) $lng];
            }
        }

        return $this->normalizePointPayload($points);
    }

    /**
     * @param list<array{lat: float, lng: float}> $points
     */
    private function assertChallengeSetSpreadAllowed(array $points): void
    {
        if (count($points) < 2) {
            return;
        }

        $maxDistance = 0.0;
        foreach ($points as $index => $point) {
            foreach (array_slice($points, $index + 1) as $other) {
                $maxDistance = max($maxDistance, self::haversineMeters($point['lat'], $point['lng'], $other['lat'], $other['lng']));
            }
        }

        if ($maxDistance > self::MAX_CHALLENGE_SET_DIAGONAL_METERS) {
            throw new \InvalidArgumentException(self::SPREAD_ERROR);
        }
    }

    private static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }

    /**
     * @param array<string, mixed> $geo
     *
     * @return list<array{level: string, label: string, key: string}>
     */
    private function addressHierarchy(array $geo): array
    {
        $address = is_array($geo['address'] ?? null) ? $geo['address'] : [];
        $provinceLabel = $this->firstAddressValue($address, ['state', 'province', 'region'])
            ?? $this->provinceFromIsoCode($address);
        $cityLabel = $this->firstAddressValue($address, ['city', 'town', 'village', 'municipality']);
        $cityLooksLikeDistrict = is_string($cityLabel) && (bool) preg_match('/district|county|区/i', $cityLabel);
        $levels = [
            'country' => ['country'],
            'province' => [],
            'city' => ['city', 'town', 'village', 'municipality'],
            'district' => ['city_district', 'district', 'county', 'borough'],
            'area' => ['suburb', 'neighbourhood', 'quarter', 'residential'],
            'place' => ['amenity', 'tourism', 'attraction', 'building'],
        ];

        $hierarchy = [];
        $seen = [];
        foreach ($levels as $level => $keys) {
            $label = null;
            if ($level === 'province') {
                $label = $provinceLabel;
            } elseif ($level === 'city' && $cityLooksLikeDistrict && $provinceLabel !== null) {
                $label = null;
            } elseif ($level === 'district' && $cityLooksLikeDistrict) {
                $label = $cityLabel;
            } else {
                $label = $this->firstAddressValue($address, $keys);
            }

            if ($label === null && $level === 'place') {
                $label = is_string($geo['name'] ?? null) ? trim((string) $geo['name']) : null;
            }

            if ($label === null || $label === '') {
                continue;
            }

            $label = mb_substr(preg_replace('/\s+/', ' ', $label) ?? $label, 0, 60);
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $hierarchy[] = ['level' => $level, 'label' => $label, 'key' => $key];
        }

        return $hierarchy;
    }

    /**
     * @param array<string, mixed> $address
     * @param list<string> $keys
     */
    private function firstAddressValue(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_string($address[$key] ?? null) && trim((string) $address[$key]) !== '') {
                return trim((string) $address[$key]);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $address
     */
    private function provinceFromIsoCode(array $address): ?string
    {
        $iso = is_string($address['ISO3166-2-lvl4'] ?? null) ? (string) $address['ISO3166-2-lvl4'] : '';
        $municipalities = [
            'CN-BJ' => 'Beijing',
            'CN-SH' => 'Shanghai',
            'CN-TJ' => 'Tianjin',
            'CN-CQ' => 'Chongqing',
        ];

        return $municipalities[$iso] ?? null;
    }

    /**
     * @param list<list<array{level: string, label: string, key: string}>> $hierarchies
     *
     * @return list<array{level: string, label: string, key: string}>
     */
    private function commonHierarchyPrefix(array $hierarchies): array
    {
        $first = $hierarchies[0] ?? [];
        $common = [];
        foreach ($first as $index => $part) {
            foreach ($hierarchies as $hierarchy) {
                if (($hierarchy[$index]['key'] ?? null) !== $part['key']) {
                    return $common;
                }
            }
            $common[] = $part;
        }

        return $common;
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedImages(Request $request): array
    {
        $images = $request->files->all('images');
        if ($images === []) {
            $single = $request->files->get('image');
            $images = $single instanceof UploadedFile ? [$single] : [];
        }

        return array_values(array_filter($images, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /**
     * @return list<mixed>
     */
    private function requestArray(Request $request, string $singleName, string $arrayName): array
    {
        $values = $request->request->all($arrayName);
        if ($values !== []) {
            return array_values($values);
        }

        $single = $request->request->get($singleName);
        return $single === null ? [] : [$single];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function readUploadedImage(UploadedFile $image): array
    {
        if (!$image->isValid()) {
            throw new \InvalidArgumentException('Image upload failed.');
        }

        if ($image->getSize() === null || $image->getSize() < 1 || $image->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('Each image must be between 1 byte and 6 MB.');
        }

        $detectedImage = @getimagesize($image->getPathname());
        $mimeType = is_array($detectedImage) && isset($detectedImage['mime'])
            ? (string) $detectedImage['mime']
            : (string) $image->getMimeType();

        if (!array_key_exists($mimeType, self::ALLOWED_IMAGE_MIME_TYPES)) {
            throw new \InvalidArgumentException('Only JPEG, PNG, WebP, and GIF images are allowed.');
        }

        $photoData = file_get_contents($image->getPathname());
        if (!is_string($photoData) || $photoData === '') {
            throw new \InvalidArgumentException('Unable to read uploaded image.');
        }

        return [$mimeType, $photoData];
    }
}
