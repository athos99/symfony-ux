# Démo « Test IA » — terminal de chat en streaming

Cette page de démonstration (`/test_ai`) montre comment afficher la réponse d'un
modèle de langage **au fil de l'eau** (token par token) avec le bundle
**Symfony AI**, **Symfony UX Turbo** (Turbo Streams) et **Stimulus**.

Elle s'inspire du mode de UI « Turbo Stream Bot » de la démo officielle
<https://ai.symfony.com/> (dépôt `symfony/ai-demo`), adapté au socle de ce projet
(Turbo + Stimulus, sans LiveComponent).

## Vue d'ensemble

```
Navigateur (Stimulus)                Serveur (Symfony)
─────────────────────                ─────────────────
1. Clic « Envoyer »  ──POST JSON──▶  TestAiController::streamResponse()
2. lit le flux SSE    ◀──SSE────────  AgentInterface::call(..., stream: true)
3. renderStreamMessage()             pour chaque TextDelta → ServerEvent(Turbo Stream)
   met à jour #ai-output
4. recopie dans le textarea
```

## Fichiers créés / modifiés

| Fichier | Rôle |
| --- | --- |
| `config/packages/ai.yaml` | Configuration du bundle AI (plateforme OpenRouter + agent `default`). |
| `composer.json` | Ajout de `symfony/ai-bundle`, `symfony/ai-agent`, `symfony/ai-open-router-platform`. |
| `src/Controller/TestAiController.php` | Page + endpoint SSE de streaming. |
| `src/Agent.php` | Définition des rôles d'agent (identifiant, nom, description). |
| `templates/test_ai/index.html.twig` | Page du terminal (textarea + boutons + zone Turbo). |
| `templates/test_ai/_stream.html.twig` | Fragments Turbo Stream (`update` / `end`). |
| `assets/controllers/test-ai_controller.js` | Contrôleur Stimulus (fetch SSE + rendu Turbo). |
| `config/services_test.yaml` | Surcharge l'agent par un `MockAgent` en environnement de test. |
| `tests/Mock/StreamingAgent.php` | Double d'agent déterministe pour les tests. |
| `tests/Controller/TestAiControllerTest.php` | Tests fonctionnels. |

## 1. Configuration du bundle Symfony AI

`config/packages/ai.yaml` déclare une plateforme (OpenRouter) et un agent
`default` qui l'utilise avec le modèle `openrouter/auto` :

```yaml
ai:
    platform:
        openrouter:
            api_key: '%env(OPENROUTER_KEY)%'
    agent:
        default:
            platform: 'ai.platform.openrouter'
            model:
                name: 'openrouter/auto'
                options:
                    temperature: .6
            include_sources: true
```

L'agent `default` est injecté dans le contrôleur via le type
`Symfony\AI\Agent\AgentInterface $default` (alias de service créé par le bundle).

## 2. Contrôleur : page + endpoint SSE

### Page

```php
#[Route('/test_ai', name: 'app_test_ai')]
public function index(): Response
{
    return $this->render('test_ai/index.html.twig');
}
```

### Endpoint de streaming

```php
#[Route('/test_ai/stream', name: 'app_test_ai_stream', methods: ['POST'])]
public function streamResponse(Request $request, AgentInterface $default): EventStreamResponse
```

Points clés :

- **`EventStreamResponse`** (Symfony) : renvoie des événements
  **Server-Sent Events** (`text/event-stream`). Le client reçoit chaque
  `ServerEvent` dès qu'il est émis.
- **Session factice** : on remplace la session par une `MockArraySessionStorage`
  pendant le streaming. Sans cela, Symfony tenterait de sauvegarder la session
  **après** l'envoi des en-têtes HTTP, ce qui lève l'erreur
  `headers already sent`. On restaure la vraie session à l'intérieur du
  générateur.
- **Appel agent en mode streamé** :

  ```php
  $execution = $default->call($messages, ['stream' => true, 'max_tokens' => 1024]);
  foreach ($execution->asStream() as $delta) {
      if ($delta instanceof TextDelta) { /* fragment de texte */ }
  }
  ```

  `asStream()` renvoie un générateur de `DeltaInterface`. On ne garde que les
  `TextDelta` (texte), en ignorant les deltas de « réflexion » ou d'outils.
- **Gestion d'erreur** : un `try/catch` pousse un Turbo Stream d'erreur dans le
  terminal (ex. crédits épuisés sur OpenRouter) au lieu d'une page HTML 500
  inexploitable par un client SSE.
- **`max_tokens`** : plafonné pour rester dans un budget de crédits raisonnable.

### Construction des messages

```php
$messages = new MessageBag(
    Message::forSystem('Tu es un assistant de démonstration. ...'),
    Message::ofUser($message),
);
```

`MessageBag` est le sac de messages envoyé à l'agent ; `Message::forSystem()`
définit le rôle du bot, `Message::ofUser()` la demande.

## 3. Fragments Turbo Stream

`templates/test_ai/_stream.html.twig` contient deux blocs :

- **`update`** : `<turbo-stream action="update" target="ai-output">` remplace le
  contenu de `#ai-output` par le texte **cumulé** de la réponse.
- **`end`** : met à jour `#ai-status` (« Terminé. »).

Chaque fragment est encapsulé dans un `ServerEvent(data, type)` :

```php
yield new ServerEvent($turboStreamHtml, 'message'); // ... puis 'done' à la fin
```

> Note : le constructeur de `ServerEvent` est **positionnel**
> `(data, type, retry, id, comment)`.

## 4. Contrôleur Stimulus

`assets/controllers/test-ai_controller.js` :

- **`send()`** : `fetch()` en `POST` JSON vers l'URL du flux (exposée via
  `data-test-ai-stream-url-value`), avec `AbortController` pour annuler.
- **`consume()`** : lit le `ReadableStream` de la réponse, découpe les événements
  SSE (séparés par une ligne vide) et appelle `processEvent()`.
- **`processEvent()`** : pour chaque événement, appelle
  `renderStreamMessage(data)` de **Turbo** (qui applique le `<turbo-stream>` et
  met à jour `#ai-output`), puis recopie le texte dans le **textarea**.
- **`reset()`** : vide le terminal et la zone de sortie.

Pourquoi `fetch` + `renderStreamMessage` plutôt que l'élément
`<turbo-stream-source>` ? Parce que ce dernier s'appuie sur `EventSource`, qui ne
gère que le **GET**. Or on doit envoyer la demande en **POST** (corps JSON).

## 5. Le terminal (textarea)

Le `textarea#ai-terminal` sert à la fois d'entrée (demande) et d'affichage
(demande + réponse). À l'envoi, Stimulus y écrit la demande, puis recopie en
direct le contenu de `#ai-output` (la réponse cumulée poussée par Turbo).

## 5ter. Sélecteur d'agent (rôles prédéfinis)

Dans la zone d'en-tête, une rangée de boutons-poussoirs lumineux (style HAL 2000)
permet de choisir un **agent** parmi des rôles prédéfinis :

| Agent | Comportement |
| --- | --- |
| **Standard** | Réponses simples en français, vocabulaire normal, phrases simples, ne ment pas. |
| **Expert** | Arrogant, charabia technique, anglicismes, phrases creuses, se vante, râleur, ment et invente. |
| **Érudit** | Érudit galant et distingué, politesse extrême, flatteur, écriture inclusive de politesse, vocabulaire recherché, prêt à mentir pour plaire. |
| **Poète** | S'exprime uniquement par des rimes et des alexandrins. |

Fonctionnement :

- Les agents sont définis **côté PHP** dans la classe `App\Agent`
  (`src/Agent.php`) : chaque agent porte un identifiant technique (`id`), un nom
  affichable (`name`) et une description (`prompt`, prompt système). La liste
  est exposée par `Agent::all()` et retrouvée par `Agent::find($id)`.
- Le contrôleur `index()` transmet la liste des agents à la vue ; le template
  génère un bouton par agent (`data-agent-id` = identifiant, libellé = nom). Le
  frontal ne connaît donc que les **identifiants** et les **noms**, jamais les
  descriptions.
- Les boutons sont **exclusifs** : un seul peut être actif à la fois. Presser le
  bouton actif le désactive (retour à « aucun agent ») ; presser un autre bouton
  active celui-ci et désactive les autres. Aucun n'est actif par défaut.
- L'état actif est porté par `aria-pressed` (accessibilité + style lumineux).
- À l'envoi, le frontal transmet **uniquement la valeur de l'agent**
  (`{"message": …, "agent": "<id>", "session": <bool>, "temperature": …}`) : la
  demande n'est plus préfixée par la description. C'est le serveur
  (`streamResponse()`) qui résout l'agent via `Agent::find()` et injecte sa
  description comme **message système**. Sans agent sélectionné, le prompt de
  démonstration par défaut est utilisé. Le transcript, lui, conserve la demande
  brute.

## 5quater. Session (mémoire des échanges)

Le bouton **« Session »** (même apparence que les boutons d'agent) est placé dans
un cadre **« OPTION »** distinct du cadre « AGENT », dans l'en-tête. Il active la
mémorisation des échanges. Toute la gestion est **côté serveur**, via la session
Symfony (aucune donnée de session n'est stockée dans le navigateur).

- **État initial** : la session est vide (aucun historique).
- **Enregistrement** : lorsque le mode session est actif, chaque tour
  (requête de l'utilisateur + réponse de l'IA) est enregistré dans la session
  Symfony (clé `test_ai.history`).
- **Contexte envoyé au modèle** : à chaque requête, le serveur reconstruit le
  contexte dans l'ordre : **description de l'agent** (message système), **tours
  enregistrés** de la session (user/assistant), puis **question courante** de
  l'utilisateur.
- **Effacer** : le bouton « Effacer » appelle l'endpoint
  `POST /test_ai/session/clear` (`clearSession()`), qui vide l'historique de la
  session, en plus de réinitialiser le terminal côté client.
- **Détail streaming** : la session réelle est démarrée **avant** le streaming
  (cookie envoyé avec les en-têtes), puis sauvegardée explicitement en fin de
  flux ; pendant le streaming, la session est remplacée par une session factice
  (voir plus haut) pour éviter l'erreur « headers already sent ».

## 5bis. Chronomètres (afficheurs 7 segments)

Sous l'indicateur `#ai-status`, quatre chronomètres à affichage digital
8 chiffres `HH:MM:SS.CC` (heure, minute, seconde, centième) sont pilotés par le
contrôleur Stimulus. À la charge de la page, tous affichent `00:00:00.00`
(rendu texte de repli, puis remplacé par des afficheurs 7 segments SVG).

| Chronomètre | Démarrage | Arrêt |
| --- | --- | --- |
| **analyse** | envoi de la question | réception du 1er token (`event: message`) |
| **réponse** | réception du 1er token | fin de la réponse (`event: done`) |
| **total** | envoi de la question | fin de la réponse (`event: done`) |
| **session** | 1re question (reprise à chaque question) | fin de la réponse (`event: done`) |

Les temps sont calculés à partir d'horodatages `performance.now()` dans une
boucle `requestAnimationFrame`, donc ils restent exacts même si des frames sont
sautées. Le rendu 7 segments n'est reconstruit que lorsque la valeur au centième
change. « session » cumule le temps de traitement actif : il se fige à la fin de
chaque réponse et reprend à la question suivante (remis à zéro au chargement de
la page).

## 6. Tests

- `tests/Controller/TestAiControllerTest.php` vérifie :
  - l'affichage de la page (`/test_ai`) et de ses éléments Stimulus ;
  - que l'endpoint `/test_ai/stream` renvoie bien du `text/event-stream` avec les
    événements `message` / `done` et un `<turbo-stream>`.
- Le test n'appelle **pas** l'API réelle : `config/services_test.yaml` remplace
  l'agent par `App\Tests\Mock\StreamingAgent`, un double qui renvoie un flux
  déterministe (« Bonjour, monde ! »).
- Comme `EventStreamResponse` est une `StreamedResponse` non tamponnée par le
  client `WebTestCase`, le test gère la requête via le noyau et capture le corps
  avec `ob_start()` / `sendContent()`.

## 7. Limites / notes

- La clé `OPENROUTER_KEY` (`.env.local`) doit avoir des crédits disponibles ; sinon
  le terminal affiche l'erreur 402 renvoyée par la plateforme.
- Le streaming nécessite un serveur qui ne bufferise pas la sortie (PHP-FPM,
  `symfony serve`, ou le serveur local de test). `flush()` est géré par
  `EventStreamResponse`.