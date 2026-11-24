<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ServerEvent;
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
     * Page de la démo : affiche le terminal de chat.
     */
    #[Route('/test_ai', name: 'app_test_ai')]
    public function index(): Response
    {
        return $this->render('test_ai/index.html.twig');
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

        // On neutralise la session le temps du streaming (voir docblock).
        $actualSession = $request->getSession();
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));

        return new EventStreamResponse(function () use ($request, $actualSession, $default, $message, $temperature) {
            // On restaure la vraie session à l'intérieur du générateur, une fois
            // la réponse partie.
            $request->setSession($actualSession);

            // Construction du sac de messages : un message système (rôle du bot)
            // + la demande de l'utilisateur.
            $messages = new MessageBag(
                Message::forSystem('Tu es un assistant de démonstration. Réponds de façon concise et pédagogique.'),
                Message::ofUser($message),
            );

            // Appel de l'agent en mode streamé : l'Execution est paresseuse,
            // les fragments arrivent au fur et à mesure. On plafonne max_tokens
            // pour rester dans un budget raisonnable et éviter les erreurs 402
            // des plateformes à crédits limités. La température est transmise
            // telle quelle au modèle (option standard de Symfony AI).
            try {
                $execution = $default->call($messages, [
                    'stream' => true,
                    'max_tokens' => 1024,
                    'temperature' => $temperature,
                ]);

                $accumulated = '';
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
                yield new ServerEvent(
                    $this->renderBlockView('test_ai/_stream.html.twig', 'update', [
                        'text' => 'Erreur de l’IA : '.$e->getMessage(),
                    ]),
                    'message',
                );
            }

            // Événement final : marque la fin du streaming côté client.
            yield new ServerEvent(
                $this->renderBlockView('test_ai/_stream.html.twig', 'end', []),
                'done',
            );
        });
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
