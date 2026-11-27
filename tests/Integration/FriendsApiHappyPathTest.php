<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Happy-path tests for FriendsApiController using real user IDs from the DB.
 * These exercise the controller business logic, not just input validation.
 */
final class FriendsApiHappyPathTest extends WebTestCase
{
    private function getClientAndUserId(): array
    {
        $client = static::createClient();
        $repo = static::getContainer()->get(UserRepository::class);
        $users = $repo->topByTotalScore(1);
        if ($users === []) {
            self::markTestIncomplete('No users in DB.');
        }

        return [$client, (int) $users[0]->getId()];
    }

    public function testListFriendsWithRealUserReturnsSuccess(): void
    {
        [$client, $userId] = $this->getClientAndUserId();
        $client->request('GET', "/api/friends?userId={$userId}");

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('friends', $payload);
        self::assertArrayHasKey('incomingInvitations', $payload);
        self::assertArrayHasKey('outgoingInvitations', $payload);
    }

    public function testNotificationCountWithRealUserReturnsSuccess(): void
    {
        [$client, $userId] = $this->getClientAndUserId();
        $client->request('GET', "/api/friends/notifications/count?userId={$userId}");

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('count', $payload);
        self::assertIsInt($payload['count']);
    }

    public function testSearchWithRealUserAndEmptyQueryReturnsSuccess(): void
    {
        [$client, $userId] = $this->getClientAndUserId();
        $client->request('GET', "/api/friends/search?userId={$userId}");

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('users', $payload);
    }

    public function testSearchWithRealUserAndQueryReturnsSuccess(): void
    {
        [$client, $userId] = $this->getClientAndUserId();
        $client->request('GET', "/api/friends/search?userId={$userId}&q=a");

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['users']);
    }

    public function testSuggestionsWithRealUserReturnsSuccess(): void
    {
        [$client, $userId] = $this->getClientAndUserId();
        $client->request('GET', "/api/friends/suggestions?userId={$userId}");

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('users', $payload);
    }

    public function testInviteSelfReturnsBadRequest(): void
    {
        [$client, $userId] = $this->getClientAndUserId();
        $client->request(
            'POST',
            '/api/friends/invitations',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['userId' => $userId, 'friendId' => $userId])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testAcceptInvitationNonexistentReturnsBadRequest(): void
    {
        [$client, $userId] = $this->getClientAndUserId();
        $client->request(
            'POST',
            '/api/friends/invitations/999999999/accept',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['userId' => $userId])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testCancelInvitationNonexistentReturnsNotFound(): void
    {
        [$client, $userId] = $this->getClientAndUserId();
        $client->request('DELETE', "/api/friends/invitations/999999999?userId={$userId}");

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testRemoveFriendNonexistentReturnsNotFound(): void
    {
        [$client, $userId] = $this->getClientAndUserId();
        $client->request('DELETE', "/api/friends/999999999?userId={$userId}");

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }
}
