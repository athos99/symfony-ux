<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Mock\StreamingAgent;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

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

    public function testPageRendersMarkdownTranscriptScreen(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_ai');

        // Le transcript est rendu dans une zone dédiée (Markdown côté client),
        // distincte de la zone de saisie (textarea).
        self::assertSelectorExists('[data-test-ai-target="screen"]');
        self::assertSelectorExists('.hal-terminal__composer textarea[data-test-ai-target="terminal"]');
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

    public function testPageRendersTemperatureCommand(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_ai');

        // La commande de température est un curseur borné [0, 2] avec sa valeur.
        self::assertSelectorExists(
            'input[data-test-ai-target="temperature"][type="range"][min="0"][max="2"]',
        );
        self::assertSelectorExists('output[data-test-ai-target="temperatureValue"]');
    }

    public function testPageRendersAgentSelectorButtons(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_ai');

        // Le sélecteur d'agent de l'en-tête expose un bouton par rôle prédéfini,
        // tous inactifs par défaut (aria-pressed="false").
        foreach (['standard', 'expert', 'serviteur', 'poete'] as $id) {
            self::assertSelectorExists(
                sprintf('button[data-test-ai-target="agent"][data-agent-id="%s"][aria-pressed="false"]', $id),
            );
        }
    }

    public function testPageRendersFourChronometersInitialisedToZero(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_ai');

        // Quatre chronomètres 7 segments sous l'indicateur « ai-status »,
        // initialement à zéro (HH:MM:SS.CC).
        foreach (['chronoAnalyse', 'chronoReponse', 'chronoTotal', 'chronoSession'] as $target) {
            self::assertSelectorExists(
                sprintf('[data-test-ai-target="%s"][role="timer"]', $target),
            );
            self::assertSelectorTextContains(
                sprintf('[data-test-ai-target="%s"]', $target),
                '00:00:00.00',
            );
        }
    }

    public function testPageRendersModelTelemetryInFooter(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_ai');

        // La télémétrie (modèle, tokens émis / reçus, débit) est logée dans le
        // pied de page, initialisée à des valeurs neutres.
        self::assertSelectorExists('.hal-footer .hal-caution__telemetry');
        self::assertSelectorTextContains('[data-test-ai-target="model"]', '—');
        self::assertSelectorTextContains('[data-test-ai-target="tokensIn"]', '0');
        self::assertSelectorTextContains('[data-test-ai-target="tokensOut"]', '0');
        self::assertSelectorTextContains('[data-test-ai-target="speed"]', '0.0');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('temperatureProvider')]
    public function testStreamEndpointForwardsBoundedTemperature(float $input, float $expected): void
    {
        StreamingAgent::$lastOptions = [];

        $kernel = self::bootKernel();
        $request = Request::create(
            '/test_ai/stream',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['message' => 'Bonjour', 'temperature' => $input]),
        );

        $response = $kernel->handle($request);
        self::assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));

        ob_start();
        $response->sendContent();
        ob_end_clean();

        $options = StreamingAgent::lastOptions();
        self::assertArrayHasKey('temperature', $options);
        self::assertSame($expected, $options['temperature']);
    }

    /**
     * @return iterable<string, array{0: float, 1: float}>
     */
    public static function temperatureProvider(): iterable
    {
        yield 'valeur normale' => [1.3, 1.3];
        yield 'borne haute dépassée' => [5.0, 2.0];
        yield 'borne basse dépassée' => [-1.0, 0.0];
    }

    public function testStreamEndpointReturnsServerSentEvents(): void
    {
        // EventStreamResponse est une StreamedResponse que le client WebTestCase ne
        // tamponne pas : on gère la requête directement via le noyau pour capturer
        // le corps généré par le callback de streaming.
        $kernel = self::bootKernel();
        $request = Request::create(
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

    public function testStreamEndpointEmitsUsageTelemetry(): void
    {
        $kernel = self::bootKernel();
        $request = Request::create(
            '/test_ai/stream',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['message' => 'Bonjour']),
        );

        $response = $kernel->handle($request);
        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        // L'événement « usage » transporte le modèle et le bilan des tokens.
        self::assertStringContainsString('event: usage', $body);

        $usage = null;
        foreach (explode("\n\n", $body) as $chunk) {
            if (str_contains($chunk, 'event: usage')) {
                foreach (explode("\n", $chunk) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $usage = json_decode(substr($line, 5), true);
                    }
                }
            }
        }

        self::assertIsArray($usage);
        self::assertSame('openrouter/auto', $usage['model']);
        self::assertSame(12, $usage['prompt']);
        self::assertSame(34, $usage['completion']);
        self::assertSame(46, $usage['total']);
    }
}
