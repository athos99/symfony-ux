<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * Double d'agent IA pour les tests : ne réalise aucun appel réseau et renvoie un
 * flux de fragments de texte déterministe, afin de tester le streaming SSE.
 */
final class StreamingAgent implements AgentInterface
{
    /**
     * Dernières options reçues par le faux agent (pour les assertions de test).
     *
     * @var array<string, mixed>
     */
    public static array $lastOptions = [];

    /**
     * @return array<string, mixed>
     */
    public static function lastOptions(): array
    {
        return self::$lastOptions;
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): Execution
    {
        self::$lastOptions = $options;

        // Fragments renvoyés « au fil de l'eau » par le faux modèle.
        $fragments = ['Bon', 'jour', ', ', 'monde', ' !'];

        return new Execution(static function () use ($fragments): \Generator {
            $text = '';
            foreach ($fragments as $fragment) {
                $text .= $fragment;

                yield new Progress(Progress::STAGE_DELTA, 'Fragment reçu.', new TextDelta($fragment));
            }

            // Métadonnée de télémétrie : le bilan des tokens est agrégé par la
            // plateforme et lu par le contrôleur pour alimenter le pupitre HAL.
            $result = new TextResult($text);
            $result->getMetadata()->add('token_usage', new TokenUsage(
                promptTokens: 12,
                completionTokens: 34,
                totalTokens: 46,
                model: 'openrouter/auto',
            ));

            yield new ResultUpdate($result);
        }, true);
    }

    public function getName(): string
    {
        return 'mock-streaming';
    }
}
