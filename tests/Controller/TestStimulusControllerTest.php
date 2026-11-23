<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TestStimulusControllerTest extends WebTestCase
{
    public function testStimulusPageIsSuccessful(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_stimulus');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Test Stimulus');
        self::assertSelectorTextContains('title', 'symfony base');
    }

    public function testStimulusPageRendersStimulusController(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_stimulus');

        self::assertSelectorExists('div[data-controller="stimulus"]');
        self::assertSelectorExists('span[data-stimulus-target="output"]');
        self::assertSelectorExists('input[data-stimulus-target="delayInput"]');
    }
}
