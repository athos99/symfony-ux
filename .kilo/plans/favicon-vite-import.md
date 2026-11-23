# Plan — Afficher le favicon en l'important via Vite (Symfony Reprise)

## Cause du problème

- `git status` indique `D public/favicon.png` : le fichier dans `public/` a été supprimé.
  `{{ asset('favicon.png') }}` génère donc `/favicon.png` → **404**.
- Le PNG subsistant est `assets/styles/favicon.png`, mais :
  - `asset()` ne « connaît » pas Vite (il ne fait que préfixer l'URL de base) ;
  - Vite/Reprise ne copie dans `public/build/` que les fichiers **importés** depuis le point d'entrée
    `assets/app.js`, qui n'importe que `./styles/app.css` (pas le PNG).
- Reprise fournit l'option `copy` : elle copie des fichiers statiques dans `public/build/` et les
  enregistre dans `manifest.json`, que `asset()` peut résoudre via
  `framework.assets.json_manifest_path`.

## Modifications

### 1. Déplacer le favicon dans un dossier dédié

- `assets/styles/favicon.png` → `assets/images/favicon.png`
- (Évite que `copy` depuis `assets/styles` copie aussi `app.css`.)

### 2. `vite.config.js` — ajouter l'option `copy` au plugin Symfony

```js
Symfony({
    devServerOrigin: 'http://localhost:5173',
    stimulus: 'assets/controllers.json',
    copy: [
        { from: 'assets/images', to: 'images' },
    ],
}),
```

### 3. `config/packages/framework.yaml` — pointer `asset()` sur le manifest

```yaml
framework:
    secret: "%env(APP_SECRET)%"
    assets:
        json_manifest_path: "%kernel.project_dir%/public/build/manifest.json"
```

### 4. `templates/base.html.twig` (ligne 6) — utiliser le chemin logique

```twig
<link rel="icon" type="image/png" href="{{ asset('build/images/favicon.png') }}">
```
La clé `build/images/favicon.png` correspond au préfixe `build/` dérivé de `publicPath: /build/`
(`manifest.json` contiendra `"build/images/favicon.png": "/build/images/favicon.<hash>.png"`).

## Vérification

- `docker exec php85 bash -c "cd /var/www/symfony-ux && yarn build"`
- Vérifier que `public/build/manifest.json` contient `build/images/favicon.png` et que
  `public/build/images/favicon.<hash>.png` existe.
- `docker exec php85 bash -c "cd /var/www/symfony-ux && bin/verify --fast"` (template/asset modifiés).
- Smoke test navigateur : `bin/verify --with-browser` (ou test fonctionnel `WebTestCase`).

## Alternative (sans toucher `framework.yaml`)

- `copy: [{ from: 'assets/images', to: 'images', hash: false }]`
- Référence directe `href="/build/images/favicon.png"` (pas de cache-busting via `asset()`).
- Ou, plus simple encore, ne pas passer par Vite : remettre le fichier dans `public/favicon.png`.
