<?php

declare(strict_types=1);

namespace App\Tests\Panther;

use Symfony\Component\Panther\PantherTestCase;

final class ChallengeBrowserTest extends PantherTestCase
{
    public function testChallengeHubFiltersAreUsableInBrowser(): void
    {
        $this->skipIfPantherBrowserIsUnavailable();

        $client = static::createPantherClient($this->pantherOptions());
        $crawler = $client->request('GET', '/challenge-hub');

        self::assertSelectorTextContains('h1', 'Challenge Hub');
        self::assertSelectorExists('[data-hub-list]');

        $crawler->filter('[data-hub-search]')->sendKeys('Beijing');
        $crawler->filter('[data-hub-author]')->sendKeys('yumin');
        $client->executeScript(
            "const sort = document.querySelector('[data-hub-sort]'); sort.value = 'newest'; sort.dispatchEvent(new Event('change', { bubbles: true }));"
        );

        self::assertSame('Beijing', $client->executeScript("return document.querySelector('[data-hub-search]').value;"));
        self::assertSame('yumin', $client->executeScript("return document.querySelector('[data-hub-author]').value;"));
        self::assertSame('newest', $client->executeScript("return document.querySelector('[data-hub-sort]').value;"));
    }

    public function testCreatorCenterLoadsForLoggedInCreatorInBrowser(): void
    {
        $this->skipIfPantherBrowserIsUnavailable();

        $client = static::createPantherClient($this->pantherOptions());
        $client->request('GET', '/creator-center');
        $client->executeScript("localStorage.setItem('lg_user', JSON.stringify({id: 6, name: 'yumin'}));");

        $crawler = $client->request('GET', '/creator-center');

        self::assertSelectorTextContains('h1', 'Creator Center');
        self::assertSelectorTextContains('.section-head p', 'Your Challenge Sets');
        self::assertSelectorExists('[data-creator-list]');
        self::assertSelectorExists('[data-refresh-mine]');
        self::assertSelectorExists('[data-stats-canvas]');
        self::assertStringContainsString('?publish=1', $crawler->filter('.creator-action-buttons a')->attr('href') ?? '');

        $crawler->filter('[data-refresh-mine]')->click();

        self::assertTrue((bool) $client->executeScript("return Boolean(document.querySelector('[data-creator-list]'));"));
    }

    /**
     * @return array<string, mixed>
     */
    private function pantherOptions(): array
    {
        $projectDir = dirname(__DIR__, 2);

        return [
            'browser' => self::CHROME,
            'webServerDir' => $projectDir,
            'router' => $projectDir . '/router.php',
            'port' => 9081,
            'readinessPath' => '/favicon.ico',
            'env' => [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '1',
            ],
        ];
    }

    private function skipIfPantherBrowserIsUnavailable(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $chromeDriver = $projectDir . '/drivers/chromedriver.exe';
        $chrome = $_SERVER['PANTHER_CHROME_BINARY'] ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';

        if (!is_file($chromeDriver)) {
            self::markTestSkipped('Panther ChromeDriver is not installed in drivers/chromedriver.exe.');
        }

        if (!is_file($chrome)) {
            self::markTestSkipped('Google Chrome is not installed at the configured PANTHER_CHROME_BINARY path.');
        }

        $driverDir = $projectDir . '/drivers';
        $path = getenv('PATH') ?: '';
        if (!str_contains($path, $driverDir)) {
            putenv('PATH=' . $driverDir . PATH_SEPARATOR . $path);
            $_SERVER['PATH'] = $driverDir . PATH_SEPARATOR . ($_SERVER['PATH'] ?? $path);
            $_ENV['PATH'] = $driverDir . PATH_SEPARATOR . ($_ENV['PATH'] ?? $path);
        }
    }
}
