<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthApiControllerTest extends WebTestCase
{
    // --- /api/auth/signup ---

    public function testSignupRejectsInvalidJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-json');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testSignupRejectsMissingName(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testSignupRejectsEmptyName(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => '   ', 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testSignupRejectsNameTooLong(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => str_repeat('a', 46), 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testSignupRejectsShortPassword(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'TestUser', 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testSignupRejectsMissingPassword(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/signup', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'TestUser'])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/auth/login ---

    public function testLoginRejectsInvalidJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], 'bad-json');

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testLoginRejectsMissingCredentials(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testLoginRejectsEmptyPassword(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'SomeUser', 'password' => ''])
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testLoginRejectsNonexistentUser(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'ThisUserDoesNotExist_xyz987', 'password' => 'REPLACE_WITH_LOCAL_SECRET'])
        );

        self::assertResponseStatusCodeSame(401);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    // --- /api/auth/me ---

    public function testMeReturnsUnauthorizedWithoutSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/auth/me');

        self::assertResponseStatusCodeSame(401);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
        self::assertNull($payload['user']);
    }

    // --- /api/auth/logout ---

    public function testLogoutSucceeds(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/logout');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
    }
}
