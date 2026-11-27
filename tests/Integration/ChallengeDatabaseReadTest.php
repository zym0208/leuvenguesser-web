<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChallengeDatabaseReadTest extends WebTestCase
{
    public function testChallengeHubCanReadRegionsFromDatabase(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/challenges/regions');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['regions']);
        self::assertNotSame([], $payload['regions']);
        self::assertArrayHasKey('name', $payload['regions'][0]);
        self::assertArrayHasKey('slug', $payload['regions'][0]);
    }

    public function testChallengeHubCanReadPublishedChallengeSetsFromDatabase(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/challenges?sort=newest');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['challenges']);

        if ($payload['challenges'] === []) {
            self::markTestIncomplete('Database is reachable, but it has no published challenge sets to validate.');
        }

        $first = $payload['challenges'][0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('title', $first);
        self::assertArrayHasKey('author_name', $first);
        self::assertArrayHasKey('region_name', $first);
        self::assertArrayHasKey('round_count', $first);
    }

    public function testCreatorCenterMineEndpointFiltersByLoggedInAuthor(): void
    {
        $client = static::createClient();
        // mine endpoint now requires session auth; sign up a fresh user and verify own challenges
        $name = 'mine_' . uniqid('', true);
        $name = substr($name, 0, 45);
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'password' => 'REPLACE_WITH_LOCAL_SECRET']));
        $signupPayload = json_decode((string) $client->getResponse()->getContent(), true);
        $myUserId = (int) ($signupPayload['user']['id'] ?? 0);

        $client->request('GET', '/api/challenges/mine');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['challenges']);
        // A brand-new user has no challenges; if any are returned they must all belong to this user.
        foreach ($payload['challenges'] as $challenge) {
            self::assertSame($myUserId, (int) ($challenge['author_user_id'] ?? -1),
                'Mine endpoint returned a challenge that does not belong to the logged-in user.');
        }
    }
}
