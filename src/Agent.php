<?php

declare(strict_types=1);

namespace App;

/**
 * Rôle d'agent prédéfini (comportement du modèle).
 *
 * Chaque agent associe :
 *  - un identifiant technique (`id`), seule valeur transmise par le frontal au
 *    serveur lors de l'envoi ;
 *  - un nom affichable (`name`), défini ici et transmis au frontal pour libellé
 *    du bouton ;
 *  - une description (`prompt`, prompt système) injectée en tête de la requête
 *    du modèle.
 *
 * La liste des agents est définie dans {@see self::all()} : le frontal ne
 * connaît que les identifiants et les noms, jamais les descriptions.
 */
final readonly class Agent
{
    public function __construct(
        public string $id,
        public string $name,
        public string $prompt,
    ) {
    }

    /**
     * Liste des agents disponibles.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self(
                'standard',
                'Standard',
                'Tu es un assistant. Donne des réponses simples en français, avec un '
                .'vocabulaire normal et facilement compréhensible. Tes phrases sont '
                .'plutôt simples. Tu ne dis pas de mensonges et tu n’inventes rien.',
            ),
            new self(
                'expert',
                'Expert',
                'Tu es un expert arrogant. Tu utilises un vocabulaire incompréhensible, '
                .'du charabia technique, des anglicismes et des mots qui impressionnent. '
                .'Tu as l’art de faire des phrases creuses et tu rappelles à chaque '
                .'instant que tu es le meilleur et que tu as travaillé dans les '
                .'meilleures entreprises. Tu es râleur. Tu adores mentir et inventer.',
            ),
            new self(
                'erudit',
                'Érudit',
                'Tu es un érudit galant et dstingué. Tu es d’une politesse extrême, très flatteur. '
                ."Tu es très respectueux et utilise que l'écriture inclusive "
                .'de politesse. Tes phrases sont élégantes et raffinées. Pour me faire '
                .'plaisir, tu es prêt à dire quelques mensonges. Tu utilises un '
                .'vocabulaire recherché et tu adores les mots rares et inusités.',
            ),
            new self(
                'poete',
                'Poète',
                'Tu es un poète. Tu t’exprimes uniquement par des vers',
            ),
        ];
    }

    /**
     * Retrouve un agent par son identifiant (les espaces superflus sont ignorés).
     * Retourne null si l'identifiant est vide ou inconnu.
     */
    public static function find(?string $id): ?self
    {
        if (null === $id) {
            return null;
        }

        $id = trim($id);
        if ('' === $id) {
            return null;
        }

        foreach (self::all() as $agent) {
            if ($agent->id === $id) {
                return $agent;
            }
        }

        return null;
    }
}
