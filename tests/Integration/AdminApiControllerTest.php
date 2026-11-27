<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminApiControllerTest extends WebTestCase
{
    // All admin endpoints must return 403 when no admin session is present.

    public function testMeRequiresAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/admin/me');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testChallengesListRequiresAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/admin/challenges');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testChallengeDetailRequiresAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/admin/challenges/1');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testPublishChallengeRequiresAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/admin/challenges/1/publish');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testUnpublishChallengeRequiresAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/admin/challenges/1/unpublish');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testDeleteChallengeRequiresAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('DELETE', '/api/admin/challenges/1');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testDeleteCommentRequiresAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('DELETE', '/api/admin/comments/1');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testUsersListRequiresAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/admin/users');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }
}
