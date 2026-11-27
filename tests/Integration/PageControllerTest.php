<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PageControllerTest extends WebTestCase
{
    public function testHomePageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body');
    }

    public function testPlayPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/play');

        self::assertResponseIsSuccessful();
    }

    public function testGamePageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/game');

        self::assertResponseIsSuccessful();
    }

    public function testGameLegacyPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/game.html');

        self::assertResponseIsSuccessful();
    }

    public function testChallengeHubPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/challenge-hub');

        self::assertResponseIsSuccessful();
    }

    public function testCreatorCenterPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/creator-center');

        self::assertResponseIsSuccessful();
    }

    public function testAdminPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function testFriendsPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/friends');

        self::assertResponseIsSuccessful();
    }
}
