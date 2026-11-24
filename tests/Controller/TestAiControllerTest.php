<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TestAiControllerTest extends WebTestCase
{
    public function testPageIsSuccessful(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_ai');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Test IA');
        self::assertSelectorTextContains('title', 'symfony base');
    }

    public function testPageRendersTerminalAndStimulusController(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_ai');

        self::assertSelectorExists('div[data-controller="test-ai"]');
        self::assertSelectorExists('textarea[data-test-ai-target="terminal"]');
        self::assertSelectorExists('button[data-test-ai-target="submit"]');
        self::assertSelectorExists('#ai-output');
    }

    public function testPageRendersHalConsoleAndReactiveEye(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_ai');

        // La console HAL 9000 et son œil réactif (état initial « idle »).
        self::assertSelectorExists('.hal-console');
        self::assertSelectorExists('[data-test-ai-target="eye"][data-state="idle"]');
        self::assertSelectorExists('#ai-status[data-test-ai-target="status"]');
    }

    public function testStreamEndpointReturnsServerSentEvents(): void
    {
        // EventStreamResponse est une StreamedResponse que le client WebTestCase ne
        // tamponne pas : on gère la requête directement via le noyau pour capturer
        // le corps généré par le callback de streaming.
        $kernel = self::bootKernel();
        $request = \Symfony\Component\HttpFoundation\Request::create(
            '/test_ai/stream',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['message' => 'Bonjour']),
        );

        $response = $kernel->handle($request);
        self::assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        // Le corps contient les événements SSE « message » (fragments Turbo Stream)
        // et l'événement final « done ».
        self::assertStringContainsString('event: message', $body);
        self::assertStringContainsString('event: done', $body);
        self::assertStringContainsString('turbo-stream', $body);
        self::assertStringContainsString('Bonjour, monde !', $body);
    }
}
