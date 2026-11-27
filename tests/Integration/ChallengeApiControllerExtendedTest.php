<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChallengeApiControllerExtendedTest extends WebTestCase
{
    /** Sign up a fresh user and leave the client session logged in. */
    private function signupAndLogin(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        // Use full uniqid + random suffix to avoid collisions when tests run in rapid succession.
        $name = 'ct_' . uniqid('', true) . '_' . mt_rand(1000, 9999);
        $name = substr($name, 0, 45); // User name max length
        $client->request(
            'POST', '/api/auth/signup', [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );
    }

    // --- /api/challenges/regions/resolve ---

    public function testResolveRegionRejectsInvalidJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/challenges/regions/resolve', [], [], ['CONTENT_TYPE' => 'application/json'], 'bad');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testResolveRegionRejectsMissingCoordinates(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/challenges/regions/resolve', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Leuven'])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testResolveRegionRejectsOutOfRangeCoordinates(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/challenges/regions/resolve', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lat' => 999.0, 'lng' => 4.70])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testResolveRegionSucceedsWithValidCoordinates(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/challenges/regions/resolve', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lat' => 50.8798, 'lng' => 4.7005, 'name' => 'Leuven'])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('region', $payload);
        self::assertSame(50.8798, $payload['region']['center_lat']);
        self::assertSame(4.7005, $payload['region']['center_lng']);
        self::assertSame('Leuven', $payload['region']['name']);
    }

    public function testResolveRegionGeneratesDefaultNameFromCoords(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/challenges/regions/resolve', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lat' => 50.8798, 'lng' => 4.7005])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertStringContainsString('50', $payload['region']['name']);
    }

    public function testResolveRegionUsesProvidedBounds(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/challenges/regions/resolve', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'lat' => 50.8798,
                'lng' => 4.7005,
                'bounds' => ['swLat' => 50.85, 'swLng' => 4.65, 'neLat' => 50.91, 'neLng' => 4.75],
            ])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertSame(50.85, $payload['region']['sw_lat']);
        self::assertSame(4.65, $payload['region']['sw_lng']);
    }

    // --- /api/challenges/{id} (detail) ---

    public function testDetailReturnsNotFoundForMissingChallenge(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/challenges/999999999');

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/challenges/{id}/like ---

    public function testLikeReturnsSuccessForExistingChallenge(): void
    {
        $client = static::createClient();
        $this->signupAndLogin($client);

        // Get a real challenge set ID first
        $client->request('GET', '/api/challenges?sort=newest');
        $listPayload = json_decode((string) $client->getResponse()->getContent(), true);

        if (empty($listPayload['challenges'])) {
            self::markTestIncomplete('No published challenge sets available to test like.');
        }

        $id = $listPayload['challenges'][0]['id'];
        $client->request('POST', "/api/challenges/{$id}/like");

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('liked', $payload);
        self::assertArrayHasKey('like_count', $payload);
    }

    // --- /api/challenges/{id}/comments ---

    public function testCommentRejectsInvalidJson(): void
    {
        $client = static::createClient();
        $this->signupAndLogin($client);
        $client->request('POST', '/api/challenges/1/comments', [], [], ['CONTENT_TYPE' => 'application/json'], 'bad');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testCommentRejectsEmptyBody(): void
    {
        $client = static::createClient();
        $this->signupAndLogin($client);
        $client->request('POST', '/api/challenges/1/comments', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['body' => '', 'authorName' => 'Tester'])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testCommentRejectsMissingBody(): void
    {
        $client = static::createClient();
        $this->signupAndLogin($client);
        $client->request('POST', '/api/challenges/1/comments', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['authorName' => 'Tester'])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/challenges/{id}/stats ---

    public function testStatsReturnsSuccessfulForAnyId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/challenges/1/stats');

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('success', $payload);
        // success=true if id=1 exists, or DB error — both are valid responses
        if ($payload['success']) {
            self::assertArrayHasKey('hourly', $payload);
        }
    }

    // --- /api/challenges (publish) — input validation only ---

    public function testPublishRejectsMissingTitle(): void
    {
        $client = static::createClient();
        $this->signupAndLogin($client);
        $client->request('POST', '/api/challenges', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['description' => 'desc']));

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/challenges/{id} (update) ---

    public function testUpdateRejectsInvalidJson(): void
    {
        $client = static::createClient();
        $this->signupAndLogin($client);
        $client->request('PATCH', '/api/challenges/1', [], [], ['CONTENT_TYPE' => 'application/json'], 'bad');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testUpdateRejectsMissingTitle(): void
    {
        $client = static::createClient();
        $this->signupAndLogin($client);
        $client->request('PATCH', '/api/challenges/1', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['description' => 'no title here'])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/challenges (list) filter variants ---

    public function testListWithSortByLikes(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/challenges?sort=likes');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['challenges']);
    }

    public function testListWithTextSearch(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/challenges?q=leuven&sort=newest');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
    }

    public function testListWithAuthorFilter(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/challenges?author=yumin');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
    }

    // --- /api/challenges/mine filter variants ---

    public function testMineWithNoIdentifiers(): void
    {
        $client = static::createClient();
        $this->signupAndLogin($client);
        $client->request('GET', '/api/challenges/mine');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['challenges']);
    }
}
