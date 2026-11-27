<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Agent;
use App\Tests\Mock\StreamingAgent;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

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
        foreach (Agent::all() as $agent) {
            self::assertSelectorExists(
                sprintf('button[data-test-ai-target="agent"][data-agent-id="%s"][aria-pressed="false"]', $agent->id),
            );
        }
    }

    public function testPageRendersAgentNamesFromPhpDefinitions(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_ai');

        // Les boutons d'agent sont générés depuis les définitions PHP (App\Agent) :
        // chaque identifiant est associé à son nom affichable.
        foreach (Agent::all() as $agent) {
            self::assertSelectorTextContains(
                sprintf('button[data-test-ai-target="agent"][data-agent-id="%s"]', $agent->id),
                $agent->name,
            );
        }
    }

    public function testPageRendersSessionToggleButton(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test_ai');

        // Le bouton « Session » (même apparence que les boutons d'agent) active
        // la mémorisation serveur ; inactif par défaut.
        self::assertSelectorExists(
            'button[data-test-ai-target="session"][aria-pressed="false"]',
        );
        self::assertSelectorTextContains(
            'button[data-test-ai-target="session"]',
            'Session',
        );
    }

    public function testStreamEndpointUsesSelectedAgentPromptAsSystemMessage(): void
    {
        StreamingAgent::$lastInput = null;

        $kernel = self::bootKernel();
        $request = Request::create(
            '/test_ai/stream',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['message' => 'Bonjour', 'agent' => 'expert']),
        );

        $response = $kernel->handle($request);
        ob_start();
        $response->sendContent();
        ob_end_clean();

        // Le serveur résout la description de l'agent à partir de son identifiant :
        // le message système correspond au prompt de l'agent « expert ».
        $input = StreamingAgent::lastInput();
        self::assertInstanceOf(MessageBag::class, $input);

        $system = $input->getSystemMessage();
        self::assertNotNull($system);
        self::assertSame(Agent::find('expert')?->prompt, $system->getContent());

        // Le message utilisateur reste la demande brute : aucune description
        // d'agent n'est injectée côté frontal.
        $user = $input->getUserMessage();
        self::assertNotNull($user);
        $content = $user->getContent();
        self::assertCount(1, $content);
        self::assertInstanceOf(Text::class, $content[0]);
        self::assertSame('Bonjour', $content[0]->getText());
    }

    public function testStreamEndpointFallsBackToDefaultPromptWithoutAgent(): void
    {
        StreamingAgent::$lastInput = null;

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
        ob_end_clean();

        // Sans agent sélectionné, le prompt système de démonstration est utilisé.
        $input = StreamingAgent::lastInput();
        self::assertInstanceOf(MessageBag::class, $input);
        $system = $input->getSystemMessage();
        self::assertNotNull($system);
        self::assertStringContainsString('assistant de démonstration', (string) $system->getContent());
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

    public function testStreamEndpointIncludesSessionHistoryInContext(): void
    {
        StreamingAgent::$lastInput = null;

        $kernel = self::bootKernel();
        $request = Request::create(
            '/test_ai/stream',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['message' => 'Question 2', 'agent' => 'expert', 'session' => true]),
        );
        $session = new Session(new MockArraySessionStorage());
        $session->set('test_ai.history', [
            ['user' => 'Question 1', 'assistant' => 'Réponse 1'],
        ]);
        $request->setSession($session);

        $response = $kernel->handle($request);
        ob_start();
        $response->sendContent();
        ob_end_clean();

        // Contexte reconstruit : système (agent) + historique (user/assistant) +
        // question courante, soit 4 messages.
        $input = StreamingAgent::lastInput();
        self::assertInstanceOf(MessageBag::class, $input);
        $messages = $input->getMessages();
        self::assertCount(4, $messages);

        $system = $input->getSystemMessage();
        self::assertNotNull($system);
        self::assertSame(Agent::find('expert')?->prompt, $system->getContent());

        $historyUser = $messages[1]->getContent();
        self::assertIsArray($historyUser);
        self::assertInstanceOf(Text::class, $historyUser[0]);
        self::assertSame('Question 1', $historyUser[0]->getText());

        $currentUser = $messages[3]->getContent();
        self::assertIsArray($currentUser);
        self::assertInstanceOf(Text::class, $currentUser[0]);
        self::assertSame('Question 2', $currentUser[0]->getText());
    }

    public function testStreamEndpointRecordsTurnInSessionWhenEnabled(): void
    {
        StreamingAgent::$lastInput = null;

        $kernel = self::bootKernel();
        $request = Request::create(
            '/test_ai/stream',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['message' => 'Bonjour', 'session' => true]),
        );
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $response = $kernel->handle($request);
        ob_start();
        $response->sendContent();
        ob_end_clean();

        // Le tour (requête + réponse) est enregistré dans la session.
        $history = $session->get('test_ai.history');
        self::assertIsArray($history);
        self::assertCount(1, $history);
        $turn = $history[0];
        self::assertIsArray($turn);
        self::assertSame('Bonjour', $turn['user']);
        self::assertSame('Bonjour, monde !', $turn['assistant']);
    }

    public function testStreamEndpointIgnoresSessionWhenDisabled(): void
    {
        StreamingAgent::$lastInput = null;

        $kernel = self::bootKernel();
        $request = Request::create(
            '/test_ai/stream',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['message' => 'Bonjour', 'session' => false]),
        );
        $session = new Session(new MockArraySessionStorage());
        $session->set('test_ai.history', [
            ['user' => 'Ancienne question', 'assistant' => 'Ancienne réponse'],
        ]);
        $request->setSession($session);

        $response = $kernel->handle($request);
        ob_start();
        $response->sendContent();
        ob_end_clean();

        // Sans session : contexte système + question courante uniquement, et
        // l'historique préexistant reste inchangé.
        $input = StreamingAgent::lastInput();
        self::assertInstanceOf(MessageBag::class, $input);
        self::assertCount(2, $input->getMessages());
        self::assertSame(
            [['user' => 'Ancienne question', 'assistant' => 'Ancienne réponse']],
            $session->get('test_ai.history'),
        );
    }

    public function testSessionClearEndpointEmptiesHistory(): void
    {
        $kernel = self::bootKernel();
        $request = Request::create('/test_ai/session/clear', 'POST');
        $session = new Session(new MockArraySessionStorage());
        $session->set('test_ai.history', [
            ['user' => 'Question', 'assistant' => 'Réponse'],
        ]);
        $request->setSession($session);

        $response = $kernel->handle($request);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame([], $session->get('test_ai.history', []));
    }
}
