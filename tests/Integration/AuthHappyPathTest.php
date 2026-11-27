<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Happy-path tests for AuthApiController: signup → login → me → logout.
 * Also exercises AuthSessionManager::login, currentUser, isAdmin.
 */
final class AuthHappyPathTest extends WebTestCase
{
    public function testSignupSucceeds(): void
    {
        $client = static::createClient();
        $name = 'testuser_' . substr(uniqid(), 0, 8);

        $client->request(
            'POST',
            '/api/auth/signup',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('user', $payload);
        self::assertSame($name, $payload['user']['name']);
    }

    public function testSignupThenMeReturnsUser(): void
    {
        $client = static::createClient();
        $name = 'testsession_' . substr(uniqid(), 0, 8);

        // Signup (sets session cookie automatically)
        $client->request(
            'POST',
            '/api/auth/signup',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );
        self::assertResponseStatusCodeSame(201);

        // GET /api/auth/me — session should still be active
        $client->request('GET', '/api/auth/me');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertSame($name, $payload['user']['name']);
    }

    public function testSignupRejectsDuplicateName(): void
    {
        $client = static::createClient();
        $name = 'dupuser_' . substr(uniqid(), 0, 8);

        $body = json_encode(['name' => $name, 'password' => 'REPLACE_WITH_LOCAL_SECRET']);

        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testLoginWithValidCredentialsSucceeds(): void
    {
        $client = static::createClient();
        $name = 'loginok_' . substr(uniqid(), 0, 8);
        $password = 'REPLACE_WITH_LOCAL_SECRET';

        // Create user via signup
        $client->request(
            'POST',
            '/api/auth/signup',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'password' => $password])
        );

        // Logout first so we have a clean state
        $client->request('POST', '/api/auth/logout');

        // Login
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'password' => $password])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertSame($name, $payload['user']['name']);
    }

    public function testLoginWithWrongPasswordReturnsUnauthorized(): void
    {
        $client = static::createClient();
        $name = 'wrongpw_' . substr(uniqid(), 0, 8);

        $client->request(
            'POST',
            '/api/auth/signup',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );

        $client->request('POST', '/api/auth/logout');

        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );

        self::assertResponseStatusCodeSame(401);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testSignupThenLogoutThenMeReturnsUnauthorized(): void
    {
        $client = static::createClient();
        $name = 'logoutme_' . substr(uniqid(), 0, 8);

        $client->request(
            'POST',
            '/api/auth/signup',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );

        $client->request('POST', '/api/auth/logout');
        $client->request('GET', '/api/auth/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginThenAdminEndpointReturnsForbiddenForNonAdmin(): void
    {
        $client = static::createClient();
        $name = 'notadmin_' . substr(uniqid(), 0, 8);

        $client->request(
            'POST',
            '/api/auth/signup',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );

        // Logged in as non-admin → admin endpoint should return 403
        $client->request('GET', '/api/admin/me');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }
}
