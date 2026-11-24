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

/**
 * Double d'agent IA pour les tests : ne réalise aucun appel réseau et renvoie un
 * flux de fragments de texte déterministe, afin de tester le streaming SSE.
 */
final class StreamingAgent implements AgentInterface
{
    public function call(string|MessageBag|UserMessage $input, array $options = []): Execution
    {
        // Fragments renvoyés « au fil de l'eau » par le faux modèle.
        $fragments = ['Bon', 'jour', ', ', 'monde', ' !'];

        return new Execution(static function () use ($fragments): \Generator {
            $text = '';
            foreach ($fragments as $fragment) {
                $text .= $fragment;

                yield new Progress(Progress::STAGE_DELTA, 'Fragment reçu.', new TextDelta($fragment));
            }

            yield new ResultUpdate(new TextResult($text));
        }, true);
    }

    public function getName(): string
    {
        return 'mock-streaming';
    }
}
