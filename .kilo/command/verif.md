---
description: Exécuter le harnais de vérification Symfony
---

Exécute le harnais de vérification dans le conteneur `php85` :

```bash
docker exec php85 bash -c "cd /var/www/symfony-ux && bin/verify $ARGUMENTS"
```

Le harnais vérifie Composer, le diff Git, les linters Symfony, Doctrine si PostgreSQL est joignable, les assets et PHPUnit. Corrige toute erreur puis relance la commande jusqu'à obtenir un résultat vert. Ne conclus pas la tâche tant que le harnais n'est pas passé.
