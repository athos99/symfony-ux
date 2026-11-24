# AGENTS.md — Projet Symfony UX

## Faits vérifiés

- Symfony **8.1.\***, PHP **8.5** dans le conteneur (`composer.json` exige `php >= 8.4`).
- Base **PostgreSQL** (`DATABASE_URL` dans `.env`), locale par défaut **`en`**.
- Assets gérés par **Symfony Reprise + Vite** (`@symfony/reprise` et `vite` dans `package.json`),
  avec Yarn pour les dépendances JavaScript. Ne pas utiliser npm ; exécuter Yarn dans le conteneur `php85`.
- **Tailwind CSS v4 + DaisyUI v5** installés et actifs :
  - `tailwindcss@4.3.5`, `@tailwindcss/vite@4.3.5` (plugin Vite officiel), `daisyui@5.8.2` (`devDependencies`).
  - Le plugin `tailwindcss()` est ajouté dans `vite.config.js` (après le plugin `Symfony()`).
  - `assets/styles/app.css` utilise la syntaxe v4 : `@import 'tailwindcss';` puis `@plugin 'daisyui';`
    (pas de `tailwind.config.js` ni de directives `@tailwind`).
  - Détection automatique du contenu : Tailwind v4 scanne tout le projet (templates Twig inclus),
    aucun `content: [...]` à configurer.
  - Après modif de `package.json` / `yarn.lock`, lancer `yarn install --frozen-lockfile` dans le conteneur.
- Reprise requiert Node.js **22.18+** ; le conteneur fournit Node.js 26.
- Les sources front sont dans `assets/`, Vite est configuré dans `vite.config.js` et les fichiers compilés
  sont publiés dans `public/build/`.
- Namespace racine `App\` → `src/`, `App\Tests\` → `tests/`.

## Environnement d'exécution (obligatoire)

Le code source est dans WSL : `/home/user/www/symfony-ux`.
Il est monté dans le conteneur Docker `php85` sous `/var/www/symfony-ux`
(montage `/home/user/www` → `/var/www`).

**Exécuter toute commande dans le conteneur, jamais sur l'hôte :**

```bash
docker exec php85 bash -c "cd /var/www/symfony-ux && <commande>"
```

Shell interactif :

```bash
docker exec -it php85 bash -c "cd /var/www/symfony-ux && bash"
```

Outils dans `php85` : `php` 8.5, `composer` 2.11, `node` 26, `yarn` 1.22.
Ne jamais lancer `php`, `composer`, `yarn`, `vite`, etc. directement sur l'hôte WSL/Windows.

- URL navigateur (Windows) : `http://localhost:1085/symfony-ux/public/`
- Xdebug est installé pour le debug et le code coverage depuis VS Code
  (variables du conteneur : `xdebug.start_with_request=yes`, `xdebug.mode=debug`,
  `xdebug.client_host=host.docker.internal`).

## Playwright (navigateur)

- **Chromium Playwright est installé et disponible sur l'hôte WSL** : `~/.cache/ms-playwright/`
  (`chromium-1255`, `chromium_headless_shell-1255`, version **159.0.8154.6**), dépendances système incluses.
- **Google Chrome 158.0.8127.36** est également installé (`/opt/google/chrome/chrome`) : requis par le
  MCP Playwright de Kilo, lancé avec `npx @playwright/mcp@latest --browser chrome`.
- Installation / mise à jour (sur l'hôte, hors conteneur) :

  ```bash
  npx playwright install chromium   # ou: npx playwright install chrome
  ```
- Pour le smoke test du harnais, Playwright lance Chromium dans `php85` ; installer le navigateur et
  ses bibliothèques système dans ce conteneur :

  ```bash
  docker exec php85 bash -c "cd /var/www/symfony-ux && yarn playwright install chromium"
  docker exec php85 bash -c "cd /var/www/symfony-ux && yarn playwright install-deps chromium"
  ```
- `apt-get update` émet une erreur sur les PPA `ondrej/php` et `ondrej/apache2` (changement de Label) :
  utiliser `sudo apt-get -o Acquire::AllowReleaseInfoChange=true update`.

## DaisyUI MCP server (optionnel)

DaisyUI fournit un **serveur MCP** pour que l'agent génère du code DaisyUI plus fiable
(doc officielle : `https://daisyui.com/docs/mcp/`). Il n'est **pas installé par défaut** dans ce
projet ; à configurer dans `kilo.json` (racine) ou `~/.config/kilo/kilo.jsonc` sous la clé `mcp`
(voir `https://kilo.ai/docs/automate/mcp/using-in-kilo-code`).

Trois options officielles :

1. **Blueprint** (officiel, recommandé, licence requise) — `npx -y daisyui-blueprint@latest`,
   variables d'env `LICENSE`, `EMAIL`, `FIGMA` (optionnel).
2. **Context7** (tiers gratuit) — URL distante `https://mcp.context7.com/mcp`.
3. **daisyUI GitMCP** (tiers gratuit) — `gitmcp.io/saadeghi/daisyui`.

Exemples de config Kilo (format `mcp`, transport `local` STDIO ou `remote` HTTP) :

```jsonc
{
    "mcp": {
        // Blueprint (officiel)
        "daisyui-blueprint": {
            "type": "local",
            "command": ["npx", "-y", "daisyui-blueprint@latest"],
            "environment": { "LICENSE": "VOTRE_CLE", "EMAIL": "VOTRE_EMAIL" }
        },
        // Context7 (tiers)
        "context7": {
            "type": "remote",
            "url": "https://mcp.context7.com/mcp"
        },
        // GitMCP (tiers)
        "daisyui-gitmcp": {
            "type": "remote",
            "url": "https://gitmcp.io/saadeghi/daisyui"
        }
    }
}
```

Utilisation : demander du code DaisyUI en précisant l'option, ex. « ... use context7 » /
« ... use Blueprint MCP ». Approuver les appels d'outils MCP au premier usage.

## Commandes utiles

```bash
# Cache
docker exec php85 bash -c "cd /var/www/symfony-ux && php bin/console cache:clear"

# Dépendances front-end (après modification de package.json / yarn.lock)
docker exec php85 bash -c "cd /var/www/symfony-ux && yarn install --frozen-lockfile"

# Vite (serveur de développement)
docker exec -it php85 bash -c "cd /var/www/symfony-ux && yarn dev --host 0.0.0.0"
# Le port 5173 du conteneur doit être accessible depuis l'hôte pour HMR (http://localhost:5173).

# Compiler les assets pour la production
docker exec php85 bash -c "cd /var/www/symfony-ux && yarn build"

# Vérifier le mode et les URLs actuellement rendus par Reprise
docker exec php85 bash -c "cd /var/www/symfony-ux && cat public/build/entrypoints.json"

# Migrations Doctrine
docker exec php85 bash -c "cd /var/www/symfony-ux && php bin/console doctrine:migrations:diff"
docker exec php85 bash -c "cd /var/www/symfony-ux && php bin/console doctrine:migrations:migrate"

# Tests (toute la suite, puis un filtre)
docker exec php85 bash -c "cd /var/www/symfony-ux && php bin/phpunit"
docker exec php85 bash -c "cd /var/www/symfony-ux && php bin/phpunit --filter=MaClasse"
```

## Structure

- `src/Controller/` — contrôleurs (ex. `HomeController`)
- `src/Entity/`, `src/Repository/` — Doctrine (actuellement vides)
- `src/Kernel.php`
- `templates/` — vues Twig (`base.html.twig`, `home/index.html.twig`)
- `assets/` — `app.js`, `controllers/`, `controllers.json`, `styles/app.css`
- `vite.config.js`, `package.json`, `yarn.lock` — configuration et dépendances front-end
- `public/build/` — sortie compilée Vite / Symfony Reprise (générée, non versionnée)
- `config/packages/` — configuration par bundle
- `migrations/` — migrations Doctrine (actuellement vide)
- `tests/` — PHPUnit (tests fonctionnels dans `tests/Controller/`) et Behat (contexte `tests/Behat/FeatureContext.php`)
- `features/` — scénarios Gherkin Behat (ex. `features/home.feature`)
- `translations/` — fichiers de traduction (actuellement vide)
- `public/` — racine web
- `bin/console`, `bin/phpunit`, `bin/verify`, `bin/cs`, `bin/rector`
- `behat.php` — configuration Behat 4 (profil `default`, suite `default`, contexte `App\Tests\Behat\FeatureContext`)

## Tests et qualité

- `phpunit.dist.xml` active `failOnDeprecation`, `failOnNotice`, `failOnWarning` :
  le code **et** les tests ne doivent produire **aucun** avertissement.
- En environnement de test : `twig.strict_variables: true` et suffixe de base `_test`
  (voir `when@test` dans `config/packages/`).
- **Behat** (`behat/behat` ^4.1, `behat/mink`, `behat/mink-browserkit-driver`, `require-dev`) fournit
  les tests BDD. Behat 4 utilise `behat.php` (config PHP, plus de YAML) et des attributs PHP
  (`#[Given]`, `#[When]`, `#[Then]`, `#[BeforeScenario]`). Le contexte `tests/Behat/FeatureContext.php`
  démarre le kernel Symfony en environnement `test` et pilote l'application via `KernelBrowser`
  (driver BrowserKit, sans serveur HTTP réel). Les scénarios Gherkin sont dans `features/`
  (langue `fr`). Lancer avec `composer behat` ou `php vendor/bin/behat`. `behat.php` charge les
  variables d'environnement via `Symfony\Component\Dotenv` (indispensable pour `DEFAULT_URI`, etc.).
  Behat est exécuté par `bin/verify` (étape `Behat`).
- **`rdx/behat-variables` n'est pas installable sous Behat 4** : la version publiée (1.2, 2017) exige
  `behat/behat: ^3.0` et son code repose sur `SnippetAcceptingContext` et les annotations `@AfterStep`
  / `@When`, supprimés en Behat 4 (aucun fork compatible n'existe). Le projet embarque donc un port
  interne équivalent dans `tests/Behat/BehatVariables/` :
  - `BehatVariablesExtension` (clé de config `behat_variables`) enregistre
    `BehatVariablesArgumentTransformer` via le tag `transformation.argument_transformer`
    (`TransformationExtension::ARGUMENT_TRANSFORMER_TAG`, priorité 100) ;
  - `BehatVariablesContext` fournit l'étape `I save it|that|those|them into "NOM1,NOM2"` et les hooks
    `#[AfterStep]` (capture de la valeur de retour) et `#[AfterFeature]` (vidage du stockage) ;
  - `BehatVariablesDatabase` est le stockage statique des variables scalaires ;
  - `BehatVariablesArgumentTransformer` remplace les jetons `<<NOM>>` dans les arguments scalaires,
    `PyStringNode` et `TableNode` ;
  - syntaxe identique à l'original : `Soit a value "42"` / `Et I save it into "UID"` /
    `Quand I go to "/users/<<UID>>"`. Les noms de variables doivent être alphanumériques
    (`[a-z]\w*`, insensible à la casse) et les valeurs scalaires.
  - Le port est couvert par la suite Behat `behat-variables`
    (`tests/Behat/BehatVariables/features/`, tag `@behat-variables`), distincte de la suite `default`.
- Le formatage PHP est vérifié/corrigé par **PHP CS Fixer** (`friendsofphp/php-cs-fixer`, `require-dev`),
  configuration `.php-cs-fixer.dist.php` (règles `@Symfony`, exclusions `var`, `config/bundles.php`,
  `config/reference.php`). Scripts Composer : `composer cs` (vérification `--diff`) et `composer cs:fix`
  (correction). Le cache `.php-cs-fixer.cache` et la surcharge locale `.php-cs-fixer.php` sont ignorés par Git.
- `bin/cs` formate le code PHP avec PHP CS Fixer (`bin/cs` pour corriger, `bin/cs --check` ou
  `bin/cs --dry-run` pour vérifier sans modifier) ; `composer cs` / `composer cs:fix` sont équivalents.
- **Rector** (`rector/rector`, `require-dev`) automatise les montées de version et refactorisations PHP.
  Config `rector.php` (chemins `src`, `tests`, `migrations` ; PHP 8.4 ; sets `codeQuality`,
  `typeDeclarations`, `symfonyCodeQuality` + `withComposerBased(symfony: true)` ; exclusions `var`,
  `vendor`, `config/bundles.php`, `config/reference.php`). Commandes : `bin/rector` (applique les
  modifications), `bin/rector --check` / `bin/rector --dry-run` (vérifie sans modifier),
  `composer rector:fix` (`rector process`, applique) et `composer rector` (`rector process --dry-run`).
  Le cache Rector est sous `/tmp` et la surcharge locale `.rector.php` est ignorée par Git.
- **PHPStan** (`phpstan/phpstan`, `require-dev`) analyse statiquement le code au **niveau max**.
  Extensions installées via `phpstan/extension-installer` : `phpstan/phpstan-symfony`
  (compréhension du conteneur Symfony) et `phpstan/phpstan-phpunit` (types PHPUnit).
  Config `phpstan.neon` : `level: max`, chemins `src` et `tests`, `containerXmlPath` pointant sur
  `var/cache/dev/App_KernelDevDebugContainer.xml`, `consoleApplicationLoader` sur
  `tests/console-application.php`, et `scanFiles` sur `ContainerConfigurator.php`. Le conteneur
  de dev doit être généré (`bin/console cache:clear` ou `cache:warmup`) avant l'analyse.
  Commande : `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
- Les sources front (JS, CSS, JSON) sont lintées et formatées par **Biome** (`@biomejs/biome`,
  config `biome.json`, scripts `lint`, `format`, `biome:check`, `biome:fix`).
- La génération des assets est vérifiée avec `yarn build` dans le conteneur.
- Si `public/build/entrypoints.json` contient `isProd: false` et une URL `localhost:5173`, Reprise sert
  le manifeste du serveur Vite de développement : lancer `yarn dev` dans le conteneur pour le HMR ou
  relancer `yarn build` pour revenir aux fichiers compilés. En production, vider le cache Symfony après
  le build (`php bin/console cache:clear`) car Reprise y met les entrypoints en cache.

## Harnais de vérification (obligatoire)

Le harnais est encapsulé dans le script exécutable `bin/verify` et doit être lancé dans le conteneur `php85` :

```bash
# Vérification rapide après une modification ciblée
docker exec php85 bash -c "cd /var/www/symfony-ux && bin/verify --fast"

# Smoke test navigateur Playwright (serveur PHP local démarré automatiquement)
docker exec php85 bash -c "cd /var/www/symfony-ux && bin/verify --with-browser"

# Vérification complète avant de conclure une tâche
docker exec php85 bash -c "cd /var/www/symfony-ux && bin/verify"

# Validation Doctrine forcée (échoue si PostgreSQL est injoignable)
docker exec php85 bash -c "cd /var/www/symfony-ux && bin/verify --with-db"

# Validation Doctrine ignorée explicitement
docker exec php85 bash -c "cd /var/www/symfony-ux && bin/verify --skip-db"

# Rapport de couverture de code (xdebug, écrit dans var/coverage/)
docker exec php85 bash -c "cd /var/www/symfony-ux && bin/verify --with-coverage"
```

Options disponibles : `--fast` (lint Symfony + PHPUnit, sans Composer, Git, Doctrine ni assets),
`--with-db` (valide le schéma Doctrine et échoue si PostgreSQL est injoignable), `--skip-db`
(ignore explicitement la validation Doctrine), `--with-browser` (smoke test Playwright ; le script
démarre son serveur PHP local, ou utilise l'URL définie par `SMOKE_BASE_URL`), `--with-coverage`
(exécute PHPUnit avec `XDEBUG_MODE=coverage`, génère un rapport texte et HTML dans `var/coverage/`),
`--help`.

Le harnais complet exécute `composer validate --strict --no-check-publish`, `composer audit --locked`
(advisories de sécurité sur `composer.lock`), `composer check-platform-reqs` (PHP + extensions),
`yarn audit` (vulnérabilités npm), `git diff --check` et `git diff --cached --check`,
`lint:container`, `lint:yaml config`, `lint:twig templates`, `lint:translations`, `lint:xliff`,
`yarn biome:check` (Biome), `php-cs-fixer check` (PHP CS Fixer), `phpstan analyse` (PHPStan niveau max),
`rector process --dry-run` (Rector), `yarn build`, puis `php bin/phpunit`.
Il tente `doctrine:schema:validate` lorsque PostgreSQL est joignable ; sinon il affiche un avertissement.
`composer outdated --direct` est exécuté en **avertissement non bloquant** (informations de mises à jour).
La commande Kilo `/verif` lance ce harnais et transmet les mêmes options (`--fast`, `--with-db`, `--skip-db`).

Règles :

- Après toute modification de code, lancer au minimum `bin/verify --fast`.
- Avant de conclure une tâche, lancer `bin/verify` sans option.
- Si la modification touche une route, un template ou un asset, ajouter un test fonctionnel
  `WebTestCase` et faire un smoke test Playwright sur la page concernée.
- Ajouter un test à chaque nouveau comportement ou correctif ; ne pas modifier un test sans raison.
- Si PostgreSQL est disponible, valider aussi le schéma Doctrine avec `--with-db`.

## Tests et spécification

- `phpunit.dist.xml` active `failOnDeprecation`, `failOnNotice`, `failOnWarning` : le code
  **et** les tests ne doivent produire **aucun** avertissement.
- En environnement de test : `twig.strict_variables: true` et suffixe de base `_test`
  (voir `when@test` dans `config/packages/`).
- La spec `docs/specification/site.md` impose la locale `fr_CH` (`config/packages/translation.yaml`)
  et le nom de site « symfony base » (titre par défaut de `templates/base.html.twig`, décliné par page).
  Tout écart doit être signalé.
- Le smoke test Playwright (`tests/browser/smoke.js`) vérifie : erreurs console, requêtes en échec,
  titre/favicon, comportement Stimulus, navigation Turbo et accessibilité (axe-core, violations
  `critical`/`serious` bloquantes). Le serveur PHP local utilise `tests/browser/router.php` pour
  servir correctement les fichiers statiques (le `public/index.php` seul renvoie du HTML pour les assets).

## Conventions

- Suivre PSR-12 et les conventions Symfony ; réutiliser les recipes existantes.
- Suivre le style du code existant (attributs PHP 8, classes `final`, paramètres et retours typés).
- Ne pas inventer d'informations, de commandes ou de configuration.
  En cas de doute, le signaler explicitement plutôt que deviner.
- Répondre toujours en français.
