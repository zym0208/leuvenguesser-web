<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FriendsApiControllerTest extends WebTestCase
{
    // --- /api/friends (list) ---

    public function testListRejectsMissingUserId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/friends');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testListRejectsInvalidUserId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/friends?userId=abc');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testListReturnsNotFoundForNonexistentUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/friends?userId=999999999');

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/friends/notifications/count ---

    public function testNotificationCountRejectsMissingUserId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/friends/notifications/count');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/friends/search ---

    public function testSearchRejectsMissingUserId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/friends/search?q=test');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/friends/suggestions ---

    public function testSuggestionsRejectsMissingUserId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/friends/suggestions');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/friends/invitations (POST) ---

    public function testInviteRejectsInvalidJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/friends/invitations', [], [], ['CONTENT_TYPE' => 'application/json'], 'bad-json');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testInviteRejectsMissingUserId(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/friends/invitations', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['friendId' => 2])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testInviteRejectsMissingFriendId(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/friends/invitations', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['userId' => 1])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testInviteReturnsNotFoundForNonexistentUsers(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/friends/invitations', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['userId' => 999999998, 'friendId' => 999999999])
        );

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/friends/invitations/{id}/accept ---

    public function testAcceptInvitationRejectsMissingUserId(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/friends/invitations/1/accept', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/friends/{friendId} (DELETE) ---

    public function testRemoveFriendRejectsMissingUserId(): void
    {
        $client = static::createClient();
        $client->request('DELETE', '/api/friends/1');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }
}
