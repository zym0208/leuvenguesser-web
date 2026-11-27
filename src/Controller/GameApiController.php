<?php

namespace App\Controller;

use App\Entity\GameResult;
use App\Entity\GameResultRound;
use App\Entity\User;
use App\Entity\DuelQueueEntry;
use App\Entity\Duel;
use App\Repository\ChallengeRepository;
use App\Repository\GameResultRepository;
use App\Repository\LocationRepository;
use App\Repository\UserRepository;
use App\Repository\DuelQueueRepository;
use App\Repository\DuelRepository;
use App\Service\AuthSessionManager;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/game')]
final class GameApiController extends AbstractController
{
    private const MAX_SCORE = 1000;
    private const SESSION_ROUND_LIMIT = 5;
    private const FALLBACK_DISTANCE_TOLERANCE_M = 4000.0;
    private const REGION_DIAGONAL_TOLERANCE_RATIO = 0.40;
    private const DAILY_REGION_SLUG = 'leuven';
    private const MAX_UPLOAD_BYTES = 6_000_000;
    private const ALLOWED_IMAGE_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly LocationRepository $gameLocations,
        private readonly ChallengeRepository $challenges,
        private readonly EntityManagerInterface $entityManager,
        private readonly GameResultRepository $gameResults,
        private readonly UserRepository $users,
        private readonly DuelQueueRepository $duelQueue,
        private readonly DuelRepository $duels,
        private readonly AuthSessionManager $authSession,
    ) {
    }

    #[Route('/rankings', name: 'api_game_rankings', methods: ['GET'])]
    public function rankings(Request $request): JsonResponse
    {
        $mode = $request->query->getString('mode', 'leuven');
        try {
            if ($mode === 'world') {
                $rows = $this->users->topByChallengeScore(10);
                $scoreGetter = static fn (User $u): int => $u->getChallengeScore();
            } elseif ($mode === 'duel') {
                $rows = $this->users->topByDuelScore(10);
                $scoreGetter = static fn (User $u): int => $u->getDuelScore();
            } else {
                $rows = $this->users->topByTotalScore(10);
                $scoreGetter = static fn (User $u): int => $u->getLeuvenScore();
                $rows = array_values(array_filter($rows, static fn (User $u): bool => $u->getLeuvenScore() > 0));
            }

            $rankings = array_map(static function (User $user, int $index) use ($scoreGetter): array {
                return [
                    'rank' => $index + 1,
                    'name' => $user->getName(),
                    'score' => $scoreGetter($user),
                ];
            }, $rows, array_keys($rows));

            return $this->json(['success' => true, 'rankings' => $rankings]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while fetching rankings.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/find_duel', name: 'api_game_find_duel', methods: ['GET'])]
    public function findDuel(Request $request): JsonResponse
    {
        $userId = $request->query->get('userId');
        $poll = $request->query->get('poll');

        $userEntry = null;

        // find if someone is queuing
        $matchResult = $this->duelQueue->matchPlayer();

        // check for dupes later
        if(is_numeric($userId) && $userId > 0){
            // check polling
            if(!is_null($poll)){
                if($poll == "true"){
                    $userEntry = $this->duelQueue->getLatestUserEntry($userId);
                    if(is_null($userEntry)||is_null($userEntry->getMatchedWith())){
                        $matchResult = null;
                    }
                    else $matchResult = $this->duelQueue->getLatestUserEntry($userEntry->getMatchedWith());
                }
                else if($poll == "false"){
                    // delete dupe id entries
                    $this->duelQueue->removeOldUserEntries($userId);
                    // create new entry
                    $userEntry = $this->duelQueue->addQueueEntry($userId);
                }
            }
            //error...
            else return $this->json([
                'success' => false,
                'error' => $poll
            ], Response::HTTP_BAD_REQUEST);

        }
        else{
            //error...
            return $this->json([
                'success' => false,
                'error' => $poll
            ], Response::HTTP_BAD_REQUEST);
        }

        if(!is_null($matchResult)){
            $loc = null;
            if($poll=="false"){
                // set status on the queue table
                $this->duelQueue->setMatchWith($matchResult->getId(),$userId);

                $this->duelQueue->setMatchWith($userEntry->getId(),$matchResult->getUserId());

                // set new duel on the duel table

                $duel = $this->duels->addDuel($matchResult->getUserId(), $userId);

                $row = $this->gameLocations->findRandomForRegionRoundExcluding("leuven", []);
                $this->duels->setDuelLocation($duel->getId(),$row['id']);
                $loc = $row['id'];

                $this->duelQueue->setResultMatchId($userEntry->getId(),$duel->getId());
                $this->duelQueue->setResultMatchId($matchResult->getId(),$duel->getId());
            }
            $duel = $this->duels->getDuelById($userEntry->getResultMatch());
            $loc = $duel->getLocation();

            return $this->json([
                'status' => 'matched',
                'message' => 'Match found!',
                'matched_with' => $matchResult->getUserId(),
                'matched_with_name' => $this->users->findUserById($matchResult->getUserId())->getName(),
                'match_id' => $duel->getId(),
                'location_id' => $loc
            ]);
        }
        else{

            return $this->json([
                'status' => 'waiting',
                'message' => 'Waiting for a match...',
            ]);
        }
    }

    #[Route('/round', name: 'api_game_round', methods: ['GET'])]
    public function round(Request $request): JsonResponse
    {
        $userId = $request->query->get('userId');
        $mode = $request->query->get('mode');
        $duelId = $request->query->get('duelId');
        $challengeSetId = $request->query->get('challengeSetId');
        // output: $row of location
        if(is_string($mode) && $mode == "duel" && is_numeric($duelId) && is_numeric($userId)){
            // For multiplayer, use location id if already found in match detail
            // Otherwise, pick a random map and set location id
            $duel = $this->duels->getDuelById($duelId);
            if(is_null($duel) || $duel->getStatus() == "ENDED" || ($userId != $duel->getP1() && $userId != $duel->getP2())){
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid duel and/or user id',
                ], Response::HTTP_FORBIDDEN);
            }

            $row = $duel->getLocation();

            if(is_null($row)){
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid duel and/or user id',
                ], Response::HTTP_FORBIDDEN);
            }

            $row = $this->gameLocations->findLocationById($row);

            $sessionTotalRounds = 1;

        }
        else{
            // Pick a map for single player that does not coincide with what they already played
            // Accept comma-separated exclude IDs from the client (current session tracking).
            $excludeParam = $request->query->get('excludeIds', '');
            $excludeIds = [];
            if (is_string($excludeParam) && $excludeParam !== '') {
                $excludeIds = array_values(array_filter(
                    array_map(static fn (string $id): int => (int) trim($id), explode(',', $excludeParam)),
                    static fn (int $id): bool => $id > 0
                ));
            }

            try {
                $sessionTotalRounds = self::SESSION_ROUND_LIMIT;

                if (is_numeric($userId) && (int) $userId > 0) {
                    $recentGames = $this->gameResults->recentLocationIdsForUser((int) $userId);
                    $excludeIds = array_values(array_unique(array_merge($excludeIds, $recentGames)));
                }

                if (is_numeric($challengeSetId) && (int) $challengeSetId > 0) {
                    $challengeSetId = (int) $challengeSetId;
                    $locationCount = $this->challenges->countLocations($challengeSetId);
                    $sessionTotalRounds = min(self::SESSION_ROUND_LIMIT, $locationCount);
                    $row = $this->challenges->findRandomLocationForSet($challengeSetId, $excludeIds);
                } else {
                    $row = $this->gameLocations->findRandomForRegionRoundExcluding(self::DAILY_REGION_SLUG, $excludeIds);
                }
                if ($row === null) {
                    return $this->json([
                        'success' => false,
                        'error' => 'No locations available.',
                    ], Response::HTTP_NOT_FOUND);
                }
            } catch (Exception $exception) {
                return $this->json([
                    'success' => false,
                    'error' => 'Database error while loading round.',
                    'details' => $exception->getMessage(),
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }
        }



        $photo = $this->sanitizePhotoFileName($row['photo_source']);
        if ($photo === null) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid photo file name in database.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = $this->json([
            'success' => true,
            'id' => $row['id'],
            'photo' => $photo,
            'photoUrl' => $row['has_stored_photo']
                ? $this->generateUrl('api_game_location_photo', ['id' => $row['id']])
                : null,
            'challenge' => isset($row['challenge_title']) ? [
                'id' => is_numeric($challengeSetId) ? (int) $challengeSetId : null,
                'title' => $row['challenge_title'],
                'region' => $row['region_name'] ?? null,
            ] : null,
            'map' => isset($row['center_lat']) ? [
                'center' => [
                    'lat' => (float) $row['center_lat'],
                    'lng' => (float) $row['center_lng'],
                ],
                'bounds' => [
                    'southWest' => [
                        'lat' => (float) $row['sw_lat'],
                        'lng' => (float) $row['sw_lng'],
                    ],
                    'northEast' => [
                        'lat' => (float) $row['ne_lat'],
                        'lng' => (float) $row['ne_lng'],
                    ],
                ],
            ] : null,
            'session' => [
                'totalRounds' => $sessionTotalRounds,
                'maxRounds' => self::SESSION_ROUND_LIMIT,
            ],
        ]);
        // GET must not be cached or every "new round" reuses the first JSON (same id/photo).
        $response->headers->set('Cache-Control', 'no-store, private, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    #[Route('/challenges', name: 'api_game_challenge_publish', methods: ['POST'])]
    public function publishChallenge(Request $request): JsonResponse
    {
        $lat = $request->request->get('lat');
        $lng = $request->request->get('lng');
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return $this->json(['success' => false, 'error' => 'Missing or invalid coordinates.'], Response::HTTP_BAD_REQUEST);
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return $this->json(['success' => false, 'error' => 'Coordinates out of range.'], Response::HTTP_BAD_REQUEST);
        }

        $image = $request->files->get('image');
        if (!$image instanceof UploadedFile) {
            return $this->json(['success' => false, 'error' => 'Missing image upload.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$image->isValid()) {
            return $this->json(['success' => false, 'error' => 'Image upload failed.'], Response::HTTP_BAD_REQUEST);
        }

        if ($image->getSize() === null || $image->getSize() < 1 || $image->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->json(['success' => false, 'error' => 'Image must be between 1 byte and 6 MB.'], Response::HTTP_BAD_REQUEST);
        }

        $detectedImage = @getimagesize($image->getPathname());
        $mimeType = is_array($detectedImage) && isset($detectedImage['mime'])
            ? (string) $detectedImage['mime']
            : (string) $image->getMimeType();

        if (!array_key_exists($mimeType, self::ALLOWED_IMAGE_MIME_TYPES)) {
            return $this->json(['success' => false, 'error' => 'Only JPEG, PNG, WebP, and GIF images are allowed.'], Response::HTTP_BAD_REQUEST);
        }

        $photoData = file_get_contents($image->getPathname());
        if (!is_string($photoData) || $photoData === '') {
            return $this->json(['success' => false, 'error' => 'Unable to read uploaded image.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $photoSource = sprintf(
                'challenge_%s_%s.%s',
                (new \DateTimeImmutable())->format('Ymd_His'),
                bin2hex(random_bytes(6)),
                self::ALLOWED_IMAGE_MIME_TYPES[$mimeType]
            );

            $id = $this->gameLocations->createUploadedLocation(
                $lat,
                $lng,
                $photoSource,
                $mimeType,
                $photoData
            );
        } catch (\Throwable $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while publishing challenge.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'success' => true,
            'id' => $id,
            'photo' => $photoSource,
            'photoUrl' => $this->generateUrl('api_game_location_photo', ['id' => $id]),
        ], Response::HTTP_CREATED);
    }

    #[Route('/locations/{id}/photo', name: 'api_game_location_photo', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function locationPhoto(Request $request, int $id): Response
    {
        try {
            $photo = $this->gameLocations->findStoredPhotoById($id);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while loading image.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($photo === null) {
            return $this->json(['success' => false, 'error' => 'Stored image not found.'], Response::HTTP_NOT_FOUND);
        }

        $etag = '"' . sha1($photo['data']) . '"';
        if ($request->headers->get('If-None-Match') === $etag) {
            $response = new Response('', Response::HTTP_NOT_MODIFIED);
            $response->headers->set('ETag', $etag);
            $response->headers->set('Cache-Control', 'public, max-age=604800, immutable');

            return $response;
        }

        $response = new Response($photo['data']);
        $response->headers->set('Content-Type', $photo['mime_type']);
        $response->headers->set('Content-Length', (string) strlen($photo['data']));
        $response->headers->set('Cache-Control', 'public, max-age=604800, immutable');
        $response->headers->set('ETag', $etag);
        $response->headers->set('Content-Disposition', 'inline; filename="' . addslashes($photo['photo_source']) . '"');

        return $response;
    }

    #[Route('/guess', name: 'api_game_guess', methods: ['POST'])]
    public function guess(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $id = $payload['id'] ?? null;
        if ($id === null || !is_numeric($id)) {
            return $this->json(['success' => false, 'error' => 'Missing or invalid location id.'], Response::HTTP_BAD_REQUEST);
        }
        $id = (int) $id;
        if ($id < 1) {
            return $this->json(['success' => false, 'error' => 'Missing or invalid location id.'], Response::HTTP_BAD_REQUEST);
        }

        $actual = $this->gameLocations->findLatLngById($id);
        if ($actual === null) {
            return $this->json(['success' => false, 'error' => 'Location not found.'], Response::HTTP_NOT_FOUND);
        }

        $lat = $payload['lat'] ?? null;
        $lng = $payload['lng'] ?? null;
        $hasGuess = is_numeric($lat) && is_numeric($lng);
        if ($hasGuess) {
            $lat = (float) $lat;
            $lng = (float) $lng;
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                return $this->json(['success' => false, 'error' => 'Coordinates out of range.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $distanceMeters = null;
        $score = 0;
        if ($hasGuess) {
            $distanceMeters = $this->haversineMeters($lat, $lng, $actual['lat'], $actual['lng']);
            $score = $this->calculateScore(
                $distanceMeters,
                $this->scoreToleranceMeters($payload, $id)
            );
        }

        try {
            $this->applyChallengeRoundScore($request, $payload, $score);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while storing user score.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'success' => true,
            'distanceMeters' => $distanceMeters,
            'score' => $score,
            'actual' => [
                'lat' => $actual['lat'],
                'lng' => $actual['lng'],
            ],
        ]);
    }
    #[Route('/duel_finish_poll', name: 'api_game_duel_finish_poll', methods: ['GET'])]
    public function duelFinishPoll(Request $request): JsonResponse
    {
        $userId = $request->query->get('userId');
        $duelId = $request->query->get('duelId');

        if(is_null($userId)||is_null($duelId)){
            return $this->json(['success' => false, 'error' => 'Invalid user and/or duel id.'], Response::HTTP_BAD_REQUEST);
        }

        $duel = $this->duels->getDuelById($duelId);

        if($userId==$duel->getP1()){
            $isP1 = true;
        }
        else if($userId==$duel->getP2()){
            $isP1 = false;
        }
        else{
            return $this->json(['success' => false, 'error' => 'Invalid user id.'], Response::HTTP_BAD_REQUEST);
        }

        if($duel->areBothFinish()){
            return $this->json([
                'success' => true,
                'status' => 'finished',
                'P1' => $duel->getP1(),
                'P1Name' => $this->users->findUserById($duel->getP1())->getName(),
                'P2' => $duel->getP2(),
                'P2Name' => $this->users->findUserById($duel->getP2())->getName(),
                'P1Score' => $duel->getP1Score(),
                'P2Score' => $duel->getP2Score(),
                'isP1' => $isP1
            ]);
        }
        else{
            return $this->json([
                'success' => true,
                'status' => 'waiting',
            ]);
        }
    }

    #[Route('/duel_finish', name: 'api_game_duel_finish', methods: ['POST'])]
    public function duelFinish(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }
        if(is_null($payload['duelId'])){
            return $this->json(['success' => false, 'error' => 'Invalid duel id.'], Response::HTTP_BAD_REQUEST);
        }
        $duel = $this->duels->getDuelById($payload['duelId']);
        $isP1 = true;

        $rounds = $this->normalizeResultRounds($payload['rounds'] ?? null, $payload['roundScores'] ?? null);
        $roundCount = count($rounds);
        if ($roundCount < 1 || $roundCount > self::SESSION_ROUND_LIMIT) {
            throw new \InvalidArgumentException(sprintf('Result must contain 1-%d rounds.', self::SESSION_ROUND_LIMIT));
        }

        $userId = $payload['userId'] ?? null;
        $userId = is_numeric($userId) && (int) $userId > 0 ? (int) $userId : null;

        $totalScore = array_sum(array_map(static fn (array $round): int => $round['score'], $rounds));

        if($payload['userId']==$duel->getP1()){
            $isP1 = true;
            $this->duels->setScore($payload['duelId'],$totalScore, $isP1);
        }
        else if($payload['userId']==$duel->getP2()){
            $isP1 = false;
            $this->duels->setScore($payload['duelId'],$totalScore, $isP1);
        }
        else{
            return $this->json(['success' => false, 'error' => 'Invalid user id.'], Response::HTTP_BAD_REQUEST);
        }

        $duel = $this->duels->getDuelById((int) $payload['duelId']);
        if ($duel === null) {
            return $this->json(['success' => false, 'error' => 'Invalid duel id.'], Response::HTTP_BAD_REQUEST);
        }

        if ($duel->areBothFinish() && $duel->getStatus() !== 'ENDED') {
            $this->applyDuelWinnerScores($duel);
            $this->duels->setStatus($duel->getId(), 'ENDED');
        }

        //return if both has finished or not
        if($duel->areBothFinish()){
            return $this->json([
                'success' => true,
                'status' => 'finished',
                'P1' => $duel->getP1(),
                'P1Name' => $this->users->findUserById($duel->getP1())->getName(),
                'P2' => $duel->getP2(),
                'P2Name' => $this->users->findUserById($duel->getP2())->getName(),
                'P1Score' => $duel->getP1Score(),
                'P2Score' => $duel->getP2Score(),
                'isP1' => $isP1
            ]);
        }
        else{
            return $this->json([
                'success' => true,
                'status' => 'waiting',
            ]);
        }
    }

    #[Route('/daily/finish', name: 'api_game_daily_finish', methods: ['POST'])]
    public function dailyFinish(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->saveGameResult($request, $payload, GameResult::MODE_LEUVEN, null, self::SESSION_ROUND_LIMIT);
        } catch (\Throwable $throwable) {
            if ($throwable instanceof \InvalidArgumentException) {
                return $this->json(['success' => false, 'error' => $throwable->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            return $this->json([
                'success' => false,
                'error' => 'Database error while saving result.',
                'details' => $throwable->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'success' => true,
            'totalScore' => $result['totalScore'],
            'isPersonalBest' => $result['isPersonalBest'],
            'previousBest' => $result['previousBest'],
            'resultId' => $result['resultId'],
        ]);
    }

    #[Route('/challenge/finish', name: 'api_game_challenge_finish', methods: ['POST'])]
    public function challengeFinish(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $challengeSetId = $payload['challengeSetId'] ?? null;
        if (!is_numeric($challengeSetId) || (int) $challengeSetId < 1) {
            return $this->json(['success' => false, 'error' => 'Missing or invalid challenge set id.'], Response::HTTP_BAD_REQUEST);
        }
        $challengeSetId = (int) $challengeSetId;

        try {
            $maxRounds = min(self::SESSION_ROUND_LIMIT, $this->challenges->countLocations($challengeSetId));
            if ($maxRounds < 1) {
                return $this->json(['success' => false, 'error' => 'Challenge set has no locations.'], Response::HTTP_BAD_REQUEST);
            }

            $result = $this->saveGameResult($request, $payload, GameResult::MODE_WORLD, $challengeSetId, $maxRounds);
        } catch (\Throwable $throwable) {
            if ($throwable instanceof \InvalidArgumentException) {
                return $this->json(['success' => false, 'error' => $throwable->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            return $this->json([
                'success' => false,
                'error' => 'Database error while saving result.',
                'details' => $throwable->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'success' => true,
            'totalScore' => $result['totalScore'],
            'isPersonalBest' => $result['isPersonalBest'],
            'previousBest' => $result['previousBest'],
            'resultId' => $result['resultId'],
        ]);
    }

    #[Route('/daily/rankings', name: 'api_game_daily_rankings', methods: ['GET'])]
    public function dailyRankings(): JsonResponse
    {
        try {
            $rows = $this->gameResults->leaderboardRows(GameResult::MODE_LEUVEN, null);

            $rankings = array_map(static function (array $row, int $index): array {
                return [
                    'rank' => $index + 1,
                    'name' => (string) $row['name'],
                    'score' => (int) $row['best_score'],
                ];
            }, $rows, array_keys($rows));

            return $this->json(['success' => true, 'rankings' => $rankings]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while fetching daily rankings.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/challenge/rankings', name: 'api_game_challenge_rankings', methods: ['GET'])]
    public function challengeRankings(Request $request): JsonResponse
    {
        $challengeSetId = $request->query->get('challengeSetId');
        if (!is_numeric($challengeSetId) || (int) $challengeSetId < 1) {
            return $this->json(['success' => false, 'error' => 'Missing or invalid challenge set id.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $rows = $this->gameResults->leaderboardRows(GameResult::MODE_WORLD, (int) $challengeSetId);

            $rankings = array_map(static function (array $row, int $index): array {
                return [
                    'rank' => $index + 1,
                    'name' => (string) $row['name'],
                    'score' => (int) $row['best_score'],
                ];
            }, $rows, array_keys($rows));

            return $this->json(['success' => true, 'rankings' => $rankings]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while fetching challenge rankings.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{resultId: int, totalScore: int, previousBest: int|null, isPersonalBest: bool}
     *
     * @throws Exception
     */
    private function saveGameResult(Request $request, array $payload, string $mode, ?int $challengeSetId, int $expectedRounds): array
    {
        $rounds = $this->normalizeResultRounds($payload['rounds'] ?? null, $payload['roundScores'] ?? null);
        $roundCount = count($rounds);
        if ($roundCount < 1 || $roundCount > self::SESSION_ROUND_LIMIT) {
            throw new \InvalidArgumentException(sprintf('Result must contain 1-%d rounds.', self::SESSION_ROUND_LIMIT));
        }
        if ($roundCount !== $expectedRounds) {
            throw new \InvalidArgumentException(sprintf('Result must contain %d rounds.', $expectedRounds));
        }

        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            throw new \InvalidArgumentException('Login required to save results.');
        }

        $userId = (int) $user->getId();
        $totalScore = array_sum(array_map(static fn (array $round): int => $round['score'], $rounds));

        $previousBest = $this->gameResults->previousBestTotalScore($mode, $challengeSetId, $userId);
        $isPersonalBest = $previousBest === null || $totalScore > $previousBest;

        $gameResult = new GameResult();
        $gameResult
            ->setMode($mode)
            ->setChallengeSetId($challengeSetId)
            ->setUserId($userId)
            ->setTotalScore($totalScore)
            ->setRoundCount($roundCount);

        foreach ($rounds as $round) {
            $roundEntity = new GameResultRound();
            $roundEntity
                ->setRoundNumber($round['roundNumber'])
                ->setLocationId($round['locationId'])
                ->setDistanceMeters($round['distanceMeters'])
                ->setScore($round['score']);
            $gameResult->addRound($roundEntity);
        }

        $this->gameResults->persistNewResultWithRounds($gameResult);
        $this->applyUserScoresAfterGameFinish($user, $mode, $totalScore);

        return [
            'resultId' => $gameResult->getId() ?? 0,
            'totalScore' => $totalScore,
            'previousBest' => $previousBest,
            'isPersonalBest' => $isPersonalBest,
        ];
    }

    /**
     * @return list<array{
     *     roundNumber: int,
     *     locationId: int|null,
     *     distanceMeters: float|null,
     *     score: int
     * }>
     */
    private function normalizeResultRounds(mixed $rounds, mixed $fallbackScores): array
    {
        if (!is_array($rounds) && is_array($fallbackScores)) {
            $rounds = array_map(static fn (mixed $score, int $index): array => [
                'roundNumber' => $index + 1,
                'score' => $score,
            ], $fallbackScores, array_keys($fallbackScores));
        }

        if (!is_array($rounds)) {
            throw new \InvalidArgumentException('Missing result rounds.');
        }

        $normalized = [];
        foreach (array_values($rounds) as $index => $round) {
            if (!is_array($round)) {
                throw new \InvalidArgumentException('Invalid result round.');
            }

            $roundNumber = $round['roundNumber'] ?? ($index + 1);
            if (!is_numeric($roundNumber) || (int) $roundNumber < 1) {
                throw new \InvalidArgumentException('Invalid round number.');
            }

            $locationId = $round['locationId'] ?? null;
            $normalized[] = [
                'roundNumber' => (int) $roundNumber,
                'locationId' => is_numeric($locationId) && (int) $locationId > 0 ? (int) $locationId : null,
                'distanceMeters' => $this->nonNegativeNumberOrNull($round['distanceMeters'] ?? null),
                'score' => max(0, min(self::MAX_SCORE, (int) ($round['score'] ?? 0))),
            ];
        }

        return $normalized;
    }

    private function nonNegativeNumberOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Invalid distance in result round.');
        }
        $number = (float) $value;
        if ($number < 0.0) {
            throw new \InvalidArgumentException('Distance in result round is out of range.');
        }

        return $number;
    }

    private function sanitizePhotoFileName(string $name): ?string
    {
        $base = basename(str_replace('\\', '/', $name));
        if ($base === '' || $base === '.' || $base === '..') {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $base)) {
            return null;
        }

        return $base;
    }


    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }

    private function calculateScore(float $distanceMeters, float $toleranceMeters): int
    {
        if ($toleranceMeters <= 0.0 || $distanceMeters > $toleranceMeters) {
            return 0;
        }

        $ratio = min(1.0, $distanceMeters / $toleranceMeters);

        return max(0, (int) round(self::MAX_SCORE * (1.0 - $ratio) ** 1.5));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws Exception
     */
    private function scoreToleranceMeters(array $payload, int $locationId): float
    {
        $challengeSetId = $payload['challengeSetId'] ?? null;
        $region = null;

        if (is_numeric($challengeSetId) && (int) $challengeSetId > 0) {
            $region = $this->challenges->findRegionForChallengeLocation((int) $challengeSetId, $locationId);
        }

        $region ??= $this->challenges->findRegionBySlug(self::DAILY_REGION_SLUG);
        if ($region === null) {
            return self::FALLBACK_DISTANCE_TOLERANCE_M;
        }

        $diagonalMeters = $this->haversineMeters(
            (float) $region['sw_lat'],
            (float) $region['sw_lng'],
            (float) $region['ne_lat'],
            (float) $region['ne_lng']
        );

        if ($diagonalMeters <= 0.0) {
            return self::FALLBACK_DISTANCE_TOLERANCE_M;
        }

        return $diagonalMeters * self::REGION_DIAGONAL_TOLERANCE_RATIO;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws Exception
     */
    private function applyChallengeRoundScore(Request $request, array $payload, int $roundScore): void
    {
        $challengeSetId = $payload['challengeSetId'] ?? null;
        if (!is_numeric($challengeSetId) || (int) $challengeSetId < 1) {
            return;
        }

        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            return;
        }

        $user->setChallengeScore($user->getChallengeScore() + $roundScore);
        $this->entityManager->flush();
    }

    private function applyUserScoresAfterGameFinish(User $user, string $mode, int $totalScore): void
    {
        if ($mode === GameResult::MODE_LEUVEN && $totalScore > $user->getLeuvenScore()) {
            $user->setLeuvenScore($totalScore);
            $this->entityManager->flush();
        } elseif ($mode === GameResult::MODE_WORLD && $totalScore > $user->getChallengeScore()) {
            $user->setChallengeScore($totalScore);
            $this->entityManager->flush();
        }
    }

    private function applyDuelWinnerScores(Duel $duel): void
    {
        $p1Score = $duel->getP1Score();
        $p2Score = $duel->getP2Score();

        if ($p1Score === $p2Score) {
            return;
        }

        if ($p1Score > $p2Score) {
            $this->addDuelScorePoints((int) $duel->getP1(), $p1Score - $p2Score);

            return;
        }

        $this->addDuelScorePoints((int) $duel->getP2(), $p2Score - $p1Score);
    }

    private function addDuelScorePoints(int $userId, int $points): void
    {
        if ($userId < 1 || $points < 1) {
            return;
        }

        $user = $this->users->find($userId);
        if ($user === null) {
            return;
        }

        $user->setDuelScore($user->getDuelScore() + $points);
        $this->entityManager->flush();
    }
}
