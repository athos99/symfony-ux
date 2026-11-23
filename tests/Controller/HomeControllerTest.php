<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageIsSuccessful(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Test Stimulus');
        self::assertSelectorTextContains('body', 'Test Tailwind');
    }

    public function testHomePageContainsSiteNameInTitle(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSelectorTextContains('title', 'symfony base');
    }

    public function testHomePageRendersFaviconLink(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSelectorExists('link[rel="icon"]');
    }

    public function testDefaultLocaleIsFrenchSwitzerland(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        $locale = $client->getRequest()->getLocale();
        self::assertSame('fr_CH', $locale);
    }
}
