<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\ChallengeRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for high-value untested routes: challenge regions, detail, comment,
 * game daily/challenge finish, and more GameApiController paths.
 */
final class HighValueRoutesTest extends WebTestCase
{
    // ── GET /api/challenges/regions ───────────────────────────────────────────

    public function testChallengeRegionsReturnsSuccess(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/challenges/regions');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('regions', $payload);
        self::assertIsArray($payload['regions']);
    }

    // ── GET /api/challenges/{id} with real ID ─────────────────────────────────

    public function testChallengeDetailWithRealIdReturnsSuccess(): void
    {
        $client = static::createClient();
        $challengeRepo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $challengeRepo->listChallengeSets([], null);
        if ($rows === []) {
            self::markTestIncomplete('No published challenges in DB.');
        }

        $id = $rows[0]['id'];
        $client->request('GET', "/api/challenges/{$id}");

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('challenge', $payload);
        self::assertArrayHasKey('locations', $payload['challenge']);
        self::assertArrayHasKey('comments', $payload['challenge']);
    }

    // ── POST /api/challenges/{id}/comments with valid body ────────────────────

    public function testChallengeCommentWithRealIdAndValidBodySucceeds(): void
    {
        $client = static::createClient();
        // Comment endpoint now requires auth
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'cmnt_' . substr(uniqid(), 0, 8), 'password' => 'REPLACE_WITH_LOCAL_SECRET']));
        $challengeRepo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $challengeRepo->listChallengeSets([], null);
        if ($rows === []) {
            self::markTestIncomplete('No published challenges in DB.');
        }

        $id = $rows[0]['id'];
        $client->request(
            'POST',
            "/api/challenges/{$id}/comments",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['body' => 'Test comment from integration test', 'authorName' => 'TestAuthor'])
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
    }

    // ── GET /api/game/round?mode=challenge with real challengeSetId ───────────

    public function testGameRoundChallengeModeWithRealIdReturnsSuccess(): void
    {
        $client = static::createClient();
        $challengeRepo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $challengeRepo->listChallengeSets([], null);
        $challengeSetId = null;
        foreach ($rows as $row) {
            if ($challengeRepo->countLocations((int) $row['id']) > 0) {
                $challengeSetId = $row['id'];
                break;
            }
        }

        if ($challengeSetId === null) {
            self::markTestIncomplete('No challenge sets with locations in DB.');
        }

        $client->request('GET', "/api/game/round?mode=challenge&challengeSetId={$challengeSetId}");

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('id', $payload);
        self::assertArrayHasKey('challenge', $payload);
        self::assertNotNull($payload['challenge']);
    }

    // ── GET /api/game/round with excludeIds ───────────────────────────────────

    public function testGameRoundDailyModeWithExcludeIdsSucceeds(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/round?mode=daily&excludeIds=1,2,3');

        $response = $client->getResponse();
        $payload = json_decode((string) $response->getContent(), true);
        // Either success (locations found) or 404 (no more locations after exclusion)
        self::assertContains($response->getStatusCode(), [200, 404]);
        self::assertArrayHasKey('success', $payload);
    }

    // ── POST /api/game/daily/finish with 5 rounds ─────────────────────────────

    public function testDailyFinishWithValidRoundsSucceeds(): void
    {
        $client = static::createClient();
        // finish endpoints now require login
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'fin1_' . substr(uniqid(), 0, 8), 'password' => 'REPLACE_WITH_LOCAL_SECRET']));

        $rounds = [];
        for ($i = 1; $i <= 5; $i++) {
            $rounds[] = [
                'roundNumber' => $i,
                'locationId' => null,
                'guessLat' => 50.88,
                'guessLng' => 4.70,
                'actualLat' => 50.88,
                'actualLng' => 4.70,
                'distanceMeters' => 0.0,
                'score' => 1000,
            ];
        }

        $client->request(
            'POST',
            '/api/game/daily/finish',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['rounds' => $rounds])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('totalScore', $payload);
        self::assertArrayHasKey('isPersonalBest', $payload);
        self::assertSame(5000, $payload['totalScore']);
    }

    // ── POST /api/game/daily/finish with fallback roundScores ─────────────────

    public function testDailyFinishWithRoundScoresFallback(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'fin2_' . substr(uniqid(), 0, 8), 'password' => 'REPLACE_WITH_LOCAL_SECRET']));

        $client->request(
            'POST',
            '/api/game/daily/finish',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['roundScores' => [800, 700, 600, 500, 400]])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertSame(3000, $payload['totalScore']);
    }

    // ── POST /api/game/challenge/finish ───────────────────────────────────────

    public function testChallengeFinishWithRealChallengeSet(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'cfin_' . substr(uniqid(), 0, 8), 'password' => 'REPLACE_WITH_LOCAL_SECRET']));
        $challengeRepo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $challengeRepo->listChallengeSets([], null);
        $challengeSetId = null;
        $locationCount = 0;
        foreach ($rows as $row) {
            $cnt = $challengeRepo->countLocations((int) $row['id']);
            if ($cnt >= 1 && $cnt <= 5) {
                $challengeSetId = (int) $row['id'];
                $locationCount = $cnt;
                break;
            }
        }

        if ($challengeSetId === null) {
            self::markTestIncomplete('No suitable challenge set found (need 1-5 locations).');
        }

        $rounds = [];
        for ($i = 1; $i <= $locationCount; $i++) {
            $rounds[] = [
                'roundNumber' => $i,
                'locationId' => null,
                'guessLat' => 50.88,
                'guessLng' => 4.70,
                'actualLat' => 50.88,
                'actualLng' => 4.70,
                'distanceMeters' => 0.0,
                'score' => 900,
            ];
        }

        $client->request(
            'POST',
            '/api/game/challenge/finish',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['challengeSetId' => $challengeSetId, 'rounds' => $rounds])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('totalScore', $payload);
    }

    // ── POST /api/game/challenge/finish with empty locations set ──────────────

    public function testChallengeFinishForChallengeWithNoLocationsBadRequest(): void
    {
        $client = static::createClient();
        $challengeRepo = static::getContainer()->get(ChallengeRepository::class);
        $regionRepo = static::getContainer()->get(\App\Repository\RegionRepository::class);

        // Create a challenge set with zero locations
        $regionId = $regionRepo->findOrCreateRegion('ChalFinish Test Region', 50.0, 4.0);
        $setId = $challengeRepo->createChallengeSet('ChalFinish Empty', null, $regionId, 'Tester', 1);
        $challengeRepo->setChallengeSetPublishedForAdmin($setId, true);

        $client->request(
            'POST',
            '/api/game/challenge/finish',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['challengeSetId' => $setId, 'rounds' => [['roundNumber' => 1, 'score' => 500]]])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);

        // Clean up
        $challengeRepo->deleteChallengeSetForAdmin($setId);
    }

    // ── GET /api/game/round with userId that exists ───────────────────────────

    public function testGameRoundDailyModeWithRealUserId(): void
    {
        $client = static::createClient();
        $userRepo = static::getContainer()->get(UserRepository::class);

        $users = $userRepo->topByTotalScore(1);
        if ($users === []) {
            self::markTestIncomplete('No users in DB.');
        }

        $userId = $users[0]->getId();
        $client->request('GET', "/api/game/round?mode=daily&userId={$userId}");

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('success', $payload);
        // May be 200 or 404 depending on available locations
        self::assertContains($client->getResponse()->getStatusCode(), [200, 404]);
    }

    // ── GET /api/game/locations/{id}/photo ────────────────────────────────────

    public function testLocationPhotoNonexistentReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/locations/999999999/photo');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLocationPhotoWithStoredPhotoReturnsImage(): void
    {
        $client = static::createClient();
        $locationRepo = static::getContainer()->get(\App\Repository\LocationRepository::class);

        $locationId = $locationRepo->createUploadedLocation(
            50.88, 4.70,
            'photo-test-' . uniqid(),
            'image/jpeg',
            'FAKEJPEG'
        );

        $client->request('GET', "/api/game/locations/{$locationId}/photo");

        self::assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $client->getResponse()->headers->get('Content-Type'));
    }

    // ── POST /api/friends/invitations with two valid distinct users ───────────

    public function testInviteWithTwoRealUsersSucceedsOrAlreadyPending(): void
    {
        $client = static::createClient();
        $userRepo = static::getContainer()->get(UserRepository::class);

        $users = $userRepo->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $userId = (int) $users[0]->getId();
        $friendId = (int) $users[1]->getId();

        $client->request(
            'POST',
            '/api/friends/invitations',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['userId' => $userId, 'friendId' => $friendId])
        );

        // May succeed (201/200) or fail if already pending/friends (400)
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('success', $payload);
        self::assertContains($client->getResponse()->getStatusCode(), [200, 400]);
    }
}
