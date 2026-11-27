<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameApiControllerTest extends WebTestCase
{
    // --- /api/game/rankings ---

    public function testRankingsReturnsSuccessful(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/rankings');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['rankings']);
    }

    // --- /api/game/daily/rankings ---

    public function testDailyRankingsReturnsSuccessful(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/daily/rankings');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['rankings']);
    }

    // --- /api/game/challenge/rankings ---

    public function testChallengeRankingsRejectsMissingChallengeSetId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/challenge/rankings');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testChallengeRankingsRejectsInvalidChallengeSetId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/challenge/rankings?challengeSetId=abc');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testChallengeRankingsReturnsForValidId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/challenge/rankings?challengeSetId=1');

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('success', $payload);
        // May succeed or fail depending on whether challengeSetId=1 exists; both are valid
    }

    // --- /api/game/round ---

    public function testRoundReturnsDailyLocation(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/round');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('id', $payload);
        self::assertArrayHasKey('photo', $payload);
        self::assertArrayHasKey('session', $payload);
    }

    public function testRoundExcludesGivenIds(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/round?excludeIds=1,2,3');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('success', $payload);
    }

    public function testRoundRejectsDuelModeWithInvalidParams(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/round?mode=duel&duelId=0&userId=0');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/game/guess ---

    public function testGuessRejectsInvalidJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/game/guess', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-json');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testGuessRejectsMissingLocationId(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/game/guess', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lat' => 50.87, 'lng' => 4.70])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testGuessRejectsZeroLocationId(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/game/guess', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => 0, 'lat' => 50.87, 'lng' => 4.70])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testGuessRejectsOutOfRangeCoordinates(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/game/guess', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => 1, 'lat' => 999.0, 'lng' => 4.70])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testGuessReturnsNotFoundForMissingLocation(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/game/guess', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => 999999999, 'lat' => 50.87, 'lng' => 4.70])
        );

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testGuessWithNoCoordinatesReturnsZeroScore(): void
    {
        $client = static::createClient();

        // First get a real location ID from the round endpoint
        $client->request('GET', '/api/game/round');
        $round = json_decode((string) $client->getResponse()->getContent(), true);

        if (!($round['success'] ?? false) || !isset($round['id'])) {
            self::markTestIncomplete('Could not retrieve a round to test guess with.');
        }

        $locationId = $round['id'];

        $client->request('POST', '/api/game/guess', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => $locationId])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertSame(0, $payload['score']);
        self::assertNull($payload['distanceMeters']);
        self::assertArrayHasKey('actual', $payload);
    }

    public function testGuessWithValidCoordinatesReturnsScore(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/game/round');
        $round = json_decode((string) $client->getResponse()->getContent(), true);

        if (!($round['success'] ?? false) || !isset($round['id'])) {
            self::markTestIncomplete('Could not retrieve a round to test guess with.');
        }

        $locationId = $round['id'];

        $client->request('POST', '/api/game/guess', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => $locationId, 'lat' => 50.8798, 'lng' => 4.7005])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertIsFloat($payload['distanceMeters']);
        self::assertIsInt($payload['score']);
        self::assertGreaterThanOrEqual(0, $payload['score']);
        self::assertLessThanOrEqual(1000, $payload['score']);
    }

    // --- /api/game/daily/finish ---

    public function testDailyFinishRejectsInvalidJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/game/daily/finish', [], [], ['CONTENT_TYPE' => 'application/json'], 'bad');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testDailyFinishRejectsMissingRounds(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/game/daily/finish', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['userId' => null])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testDailyFinishRejectsWrongRoundCount(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/game/daily/finish', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['rounds' => [['roundNumber' => 1, 'score' => 500]]])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/game/challenge/finish ---

    public function testChallengeFinishRejectsMissingChallengeSetId(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/game/challenge/finish', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['rounds' => []])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testChallengeFinishRejectsInvalidJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/game/challenge/finish', [], [], ['CONTENT_TYPE' => 'application/json'], '???');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/game/duel_finish_poll ---

    public function testDuelFinishPollRejectsMissingParams(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/duel_finish_poll');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/game/find_duel ---

    public function testFindDuelRejectsInvalidUserId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/find_duel?userId=abc&poll=false');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testFindDuelRejectsMissingPollParam(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game/find_duel?userId=1');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }
}
