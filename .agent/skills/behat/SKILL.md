---

name: behat
description: >
Instructions pour créer des scénarios Behat.
---

Tests PHP Behat
Mots-clés (GitHub) : behat, gherkin, définitions d'étapes, contextes, docker

## Démarrage rapide

- le répertoire `features/`
- les définitions d'étapes : généralement `tests/Behat/Context/*Context.php`

Lancez les tests Behat avant toute modification pour obtenir une base de régression.

Rédigez d'abord les scénarios en langage métier (acteur + action métier + résultat métier).

Ajoutez/modifiez les scénarios dans les fichiers `.feature`.

Ajoutez/modifiez les définitions d'étapes seulement une fois le langage des scénarios stabilisé.

Lancez uniquement ce que vous avez modifié (un seul fichier ou une seule ligne de scénario).

## Découverte de l'environnement (PHP + Symfony + Behat)

### Détecter la version de Behat/Gherkin (capacités du parser)

Les différentes versions ne prennent pas en charge les mêmes mots-clés/structures Gherkin. Confirmez ce que vous avez :

```bash
rg -n "\"name\":\s*\"behat/behat\"|\"behat/gherkin\"" composer.lock -S
```

## Rédiger les scénarios (`.feature`)

### Langage métier d'abord (obligatoire)

Les scénarios doivent décrire un comportement métier, pas l'implémentation technique.

Rédigez les noms de `Feature`, `Rule` et `Scenario` comme des résultats utilisateur/métier.

Utilisez des acteurs du domaine et une intention : `customer`, `guest user`, `signed-in customer`, `repayment customer`.

Gardez les détails de bas niveau hors du texte des scénarios : méthode HTTP, chemin de route, en-têtes, noms de services, interfaces de manager.

Placez la correspondance technique dans les définitions d'étapes/contextes.

N'utilisez du vocabulaire technique dans le `.feature` que lorsque le contrat de transport est lui-même l'exigence métier.

Exemples :

- Mauvais : `GET /hello/my-account/profile returns 302 for anonymous user`
- Bon : `Guest user is redirected to login before opening profile page`
- Mauvais : `POST /hello/my-account/income-update/form returns 200 on invalid payload`
- Bon : `Customer stays on income update form when provided data is invalid`

### Alignement du texte des étapes (style obligatoire)

Dans les fichiers `.feature`, alignez le texte métier afin qu'il commence dans la même colonne pour tous les mots-clés d'étape.

Gardez une espace entre le mot-clé et le texte de l'étape.

Ajustez le remplissage à gauche avant le mot-clé en fonction de la longueur du mot-clé.

Formule pour les étapes : `left_pad = base_pad + (5 - keyword_length)` où `base_pad` est l'indentation de `Given`.

Résultat pratique :

- `Given` utilise l'indentation de base.
- `When` et `Then` ont une espace de tête supplémentaire.
- `And` et `But` ont deux espaces de tête supplémentaires.

Exemple de formatage :

```text
Scenario: Customer stays on MyAccount page when no redirect applies
            Given customer is not eligible for next application and has no active current loan
             When I open MyAccount main page
             Then I should stay on MyAccount main page
              And I should see "MyAccount main page"
```

### Gardez les étapes lisibles et orientées domaine

- `Given` : préconditions/état métier.
- `When` : action métier.
- `Then` : résultat métier observable.

Préférez des verbes tels que : `opens`, `submits`, `is redirected`, `can access`, `cannot access`, `sees`.

Évitez d'exposer les détails d'implémentation dans le texte des étapes (ex. `X-Original-URI`, `AccountDetailsManagerInterface`).

### Gardez l'état cohérent

- Utilisez `Background` pour l'initialisation stable (ex. client authentifié).
- Évitez de redéfinir l'état d'authentification dans un scénario si un `Background` au niveau du fichier le définit déjà.
- Si vous devez couvrir à la fois un état authentifié et anonyme, préférez des fichiers feature (ou des suites) distincts.

### Encodez explicitement la priorité métier

Si la logique comporte des règles de priorité (ex. redirection A avant redirection B), ajoutez un scénario où les deux conditions sont vraies pour figer la priorité métier voulue.

### Gardez des assertions stables

- Pour les redirections : assertion du code de statut et de l'en-tête `Location`.
- Pour les contrats de réponse : assertion des en-têtes stables et d'une forme JSON/PDF minimale.
- Évitez les assertions UI fragiles, sauf si la stabilité du balisage est garantie.

## Définitions d'étapes (contextes)

### Où placer les nouvelles étapes

- Préférez la classe de contexte existante correspondant au domaine (ex. `MyAccountContext`, `AuthContext`, `HttpContext`).
- Gardez les aides partagées dans des contextes de base/partagés.
- Gardez les détails de transport dans les méthodes de contexte, pas dans le texte du `.feature`.

### Évitez les étapes instables

- Réinitialisez l'état mutable dans `@BeforeScenario`.
- Rendez la récupération des services explicite et typée.
- Gardez les étapes petites et à responsabilité unique.

## Lancer Behat

### Cibler rapidement un seul scénario

Utilisez le fichier + le numéro de ligne :

```bash
bin/behat -c behat.yml.dist features/foo.feature:42
```
