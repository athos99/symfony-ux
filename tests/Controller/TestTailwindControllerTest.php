<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TestTailwindControllerTest extends WebTestCase
{
    public function testTailwindPageIsSuccessful(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_tailwind');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Test Tailwind');
        self::assertSelectorTextContains('title', 'symfony base');
    }

    public function testTailwindPageRendersDaisyUiComponents(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_tailwind');

        self::assertSelectorExists('.btn');
        self::assertSelectorExists('.card');
        self::assertSelectorExists('.alert');
        self::assertSelectorExists('.badge');
    }
}
