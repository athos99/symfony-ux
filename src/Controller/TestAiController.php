<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ServerEvent;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Démonstration d'un terminal de chat IA.
 *
 * La page affiche un « terminal » (textarea) dans lequel la demande de
 * l'utilisateur et la réponse du modèle s'affichent au fil de l'eau.
 *
 * Le streaming repose sur trois briques Symfony UX :
 *  - un endpoint SSE (Server-Sent Events) qui interroge l'agent IA et pousse
 *    des fragments de Turbo Stream au fur et à mesure de leur génération ;
 *  - des Turbo Streams (balises <turbo-stream>) qui mettent à jour le DOM ;
 *  - un contrôleur Stimulus qui ouvre la connexion avec fetch(), lit le flux
 *    SSE et injecte chaque fragment via renderStreamMessage() de Turbo.
 */
final class TestAiController extends AbstractController
{
    /**
     * Prompt système utilisé lorsqu'aucun agent n'est sélectionné.
     */
    private const string DEFAULT_SYSTEM_PROMPT = 'Tu es un assistant de démonstration. Réponds de façon concise et pédagogique.';

    /**
     * Clé de session stockant l'historique des tours (requête + réponse).
     */
    private const string SESSION_KEY = 'test_ai.history';

    /**
     * Page de la démo : affiche le terminal de chat.
     *
     * La liste des agents (identifiant, nom, description) est définie côté PHP
     * dans {@see Agent::all()} et transmise à la vue : le frontal ne reçoit que
     * les identifiants et les noms.
     */
    #[Route('/test_ai', name: 'app_test_ai')]
    public function index(): Response
    {
        return $this->render('test_ai/index.html.twig', [
            'agents' => Agent::all(),
        ]);
    }

    /**
     * Endpoint de streaming SSE.
     *
     * Chaque fragment de texte généré par l'agent est encapsulé dans un
     * ServerEvent SSE dont la donnée est un Turbo Stream « update ». Le client
     * (Stimulus) reçoit ces événements et les transmet à Turbo, qui met à jour
     * la zone de sortie.
     *
     * Astuce importante : on remplace la session par une session « factice »
     * (MockArraySessionStorage) pendant le streaming. Sinon Symfony tenterait
     * de sauvegarder la session APRÈS que les en-têtes HTTP ont été envoyés,
     * ce qui provoque l'erreur « headers already sent ».
     */
    #[Route('/test_ai/stream', name: 'app_test_ai_stream', methods: ['POST'])]
    public function streamResponse(Request $request, AgentInterface $default): EventStreamResponse
    {
        // On lit la demande envoyée par le client (JSON: {"message": "..."}),
        // avec repli sur un champ de formulaire classique.
        $message = $this->extractMessage($request);

        // Température du modèle (0.0 → 2.0), bornée côté serveur.
        $temperature = $this->extractTemperature($request);

        // Agent sélectionné par le frontal (identifiant technique). La description
        // (prompt système) est résolue côté serveur à partir de {@see Agent::all()} :
        // le client ne transmet que la valeur de l'agent, jamais sa description.
        $agent = Agent::find($this->extractAgent($request));
        $systemPrompt = self::DEFAULT_SYSTEM_PROMPT;
        if ($agent instanceof Agent) {
            $systemPrompt = $agent->prompt;
        }

        // Mode session : le client indique s'il faut activer la mémorisation
        // (bouton « Session » de l'en-tête). La gestion de la session est
        // entièrement côté serveur, via la session Symfony.
        $sessionEnabled = $this->extractSessionEnabled($request);

        // On lit l'historique de la session (requêtes + réponses) AVANT de
        // neutraliser la session pour le streaming.
        $actualSession = $request->getSession();
        $history = $sessionEnabled ? $this->getHistory($actualSession) : [];

        // En mode session, on démarre la session AVANT le streaming : le cookie
        // de session est ainsi envoyé avec les en-têtes de la réponse, ce qui
        // permet de la sauvegarder en fin de flux sans « headers already sent ».
        if ($sessionEnabled) {
            $actualSession->start();
        }

        // Construction du contexte envoyé au modèle : description de l'agent
        // (message système), puis les tours enregistrés de la session, et enfin
        // la question courante de l'utilisateur.
        $messages = new MessageBag(Message::forSystem($systemPrompt));
        foreach ($history as $turn) {
            $messages->add(Message::ofUser($turn['user']));
            $messages->add(Message::ofAssistant($turn['assistant']));
        }
        $messages->add(Message::ofUser($message));

        // On neutralise la session le temps du streaming (voir docblock).
        $request->setSession(new Session(new MockArraySessionStorage()));

        return new EventStreamResponse(function () use ($request, $actualSession, $default, $message, $temperature, $messages, $sessionEnabled) {
            // On restaure la vraie session à l'intérieur du générateur, une fois
            // la réponse partie.
            $request->setSession($actualSession);

            // Appel de l'agent en mode streamé : l'Execution est paresseuse,
            // les fragments arrivent au fur et à mesure. On plafonne max_tokens
            // pour rester dans un budget raisonnable et éviter les erreurs 402
            // des plateformes à crédits limités. La température est transmise
            // telle quelle au modèle (option standard de Symfony AI).
            $execution = null;
            $accumulated = '';
            try {
                $execution = $default->call($messages, [
                    'stream' => true,
                    'max_tokens' => 1024,
                    'temperature' => $temperature,
                ]);

                foreach ($execution->asStream() as $delta) {
                    // On ne s'intéresse qu'aux fragments de texte (et non aux
                    // fragments de « réflexion » ou d'outils).
                    if (!$delta instanceof TextDelta) {
                        continue;
                    }

                    $accumulated .= $delta->getText();

                    // On pousse un Turbo Stream « update » qui remplace le contenu
                    // de #ai-output par le texte cumulé. Le client le rend via Turbo.
                    // ServerEvent(data, type) : le 2e argument est le type d'événement.
                    yield new ServerEvent(
                        $this->renderBlockView('test_ai/_stream.html.twig', 'update', [
                            'text' => $accumulated,
                        ]),
                        'message',
                    );
                }
            } catch (\Throwable $e) {
                // En cas d'erreur (crédits épuisés, réseau, modèle indisponible…),
                // on pousse un message d'erreur dans le terminal plutôt qu'une page
                // d'erreur HTML inexploitable par le client SSE.
                $accumulated = 'Erreur de l’IA : '.$e->getMessage();
                yield new ServerEvent(
                    $this->renderBlockView('test_ai/_stream.html.twig', 'update', [
                        'text' => $accumulated,
                    ]),
                    'message',
                );
            }

            // Fin du tour : on enregistre la requête et la réponse dans la session
            // (uniquement si le mode session est actif). La session ayant déjà été
            // démarrée avant le streaming, on la sauvegarde explicitement ici.
            if ($sessionEnabled) {
                $history = $this->getHistory($actualSession);
                $history[] = ['user' => $message, 'assistant' => $accumulated];
                $actualSession->set(self::SESSION_KEY, $history);
                $actualSession->save();
            }

            // Télémétrie finale : modèle employé et bilan des tokens (émis = prompt,
            // reçus = complétion). Ces métadonnées sont agrégées par la plateforme
            // pendant la consommation du flux et exposées via getMetadata(). Le client
            // en déduit le débit (tokens reçus par seconde) à partir de ses chronomètres.
            $tokenUsage = $execution?->getMetadata()->get('token_usage');
            yield new ServerEvent((string) json_encode([
                'model' => $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getModel() : null,
                'prompt' => $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getPromptTokens() : null,
                'completion' => $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getCompletionTokens() : null,
                'total' => $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getTotalTokens() : null,
            ]), 'usage');

            // Événement final : marque la fin du streaming côté client.
            yield new ServerEvent(
                $this->renderBlockView('test_ai/_stream.html.twig', 'end', []),
                'done',
            );
        });
    }

    /**
     * Efface l'historique de la session (bouton « Effacer »).
     */
    #[Route('/test_ai/session/clear', name: 'app_test_ai_session_clear', methods: ['POST'])]
    public function clearSession(Request $request): Response
    {
        $session = $request->getSession();
        $session->remove(self::SESSION_KEY);
        $session->save();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Extrait la demande du client (corps JSON ou champ de formulaire).
     */
    private function extractMessage(Request $request): string
    {
        $content = $request->getContent();
        if ('' !== $content) {
            $data = json_decode($content, true);
            if (\is_array($data) && isset($data['message']) && \is_string($data['message'])) {
                return trim($data['message']);
            }
        }

        return trim((string) $request->request->get('message', ''));
    }

    /**
     * Extrait l'identifiant de l'agent choisi (corps JSON ou champ de formulaire).
     * Retourne null lorsqu'aucun agent n'est fourni.
     */
    private function extractAgent(Request $request): ?string
    {
        $content = $request->getContent();
        if ('' !== $content) {
            $data = json_decode($content, true);
            if (\is_array($data) && isset($data['agent']) && \is_string($data['agent'])) {
                return $data['agent'];
            }
        }

        $agent = $request->request->get('agent');

        return \is_string($agent) && '' !== $agent ? $agent : null;
    }

    /**
     * Indique si le mode session est actif (corps JSON ou champ de formulaire).
     */
    private function extractSessionEnabled(Request $request): bool
    {
        $content = $request->getContent();
        if ('' !== $content) {
            $data = json_decode($content, true);
            if (\is_array($data) && isset($data['session'])) {
                return (bool) $data['session'];
            }
        }

        return $request->request->getBoolean('session');
    }

    /**
     * Historique des tours enregistrés dans la session.
     *
     * @return list<array{user: string, assistant: string}>
     */
    private function getHistory(SessionInterface $session): array
    {
        $history = $session->get(self::SESSION_KEY, []);
        if (!\is_array($history)) {
            return [];
        }

        $turns = [];
        foreach ($history as $turn) {
            if (\is_array($turn)
                && isset($turn['user'], $turn['assistant'])
                && \is_string($turn['user'])
                && \is_string($turn['assistant'])) {
                $turns[] = ['user' => $turn['user'], 'assistant' => $turn['assistant']];
            }
        }

        return $turns;
    }

    /**
     * Extrait la température demandée (corps JSON ou champ de formulaire),
     * bornée à l'intervalle [0.0, 2.0] avec une valeur par défaut de 0.7.
     */
    private function extractTemperature(Request $request): float
    {
        $temperature = 0.7;

        $content = $request->getContent();
        if ('' !== $content) {
            $data = json_decode($content, true);
            if (\is_array($data) && isset($data['temperature']) && is_numeric($data['temperature'])) {
                $temperature = (float) $data['temperature'];
            }
        }

        if (0.7 === $temperature && $request->request->has('temperature')) {
            $temperature = (float) $request->request->get('temperature');
        }

        return max(0.0, min(2.0, $temperature));
    }
}
