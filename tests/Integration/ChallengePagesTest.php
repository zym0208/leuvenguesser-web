<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChallengePagesTest extends WebTestCase
{
    public function testChallengeHubPageRendersCoreControls(): void
    {
        $client = static::createClient();
        $client->request('GET', '/challenge-hub');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Challenge Hub');
        self::assertSelectorExists('[data-hub-search]');
        self::assertSelectorExists('[data-hub-author]');
        self::assertSelectorExists('[data-hub-region]');
        self::assertSelectorExists('[data-hub-sort]');
        self::assertSelectorExists('[data-hub-list]');
    }

    public function testCreatorCenterPageRendersCoreControls(): void
    {
        $client = static::createClient();
        $client->request('GET', '/creator-center');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Creator Center');
        self::assertSelectorTextContains('.section-head p', 'Your Challenge Sets');
        self::assertSelectorExists('[data-creator-list]');
        self::assertSelectorExists('[data-refresh-mine]');
        self::assertSelectorExists('[data-stats-canvas]');
        self::assertSelectorExists('a[href="/?publish=1"]');
    }

    public function testChallengeRegionResolveEndpointAcceptsGeneratedBounds(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/challenges/regions/resolve', [
            'lat' => 50.8798,
            'lng' => 4.7005,
            'name' => 'Leuven',
            'bounds' => [
                'swLat' => 50.86,
                'swLng' => 4.68,
                'neLat' => 50.90,
                'neLng' => 4.73,
            ],
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertSame('Leuven', $payload['region']['name']);
        self::assertSame(50.8798, $payload['region']['center_lat']);
        self::assertSame(4.7005, $payload['region']['center_lng']);
        self::assertSame(50.86, $payload['region']['sw_lat']);
        self::assertSame(4.73, $payload['region']['ne_lng']);
    }

    public function testChallengeRegionResolveEndpointRejectsInvalidCoordinates(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/challenges/regions/resolve', [
            'lat' => 91,
            'lng' => 4.7005,
            'name' => 'Invalid',
        ]);

        self::assertResponseStatusCodeSame(400);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertSame('Anchor coordinates out of range.', $payload['error']);
    }
}
