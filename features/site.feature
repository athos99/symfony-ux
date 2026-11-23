# language: fr

@site
Fonctionnalité: Conformité du site
  Afin de garantir que le site respecte ses spécifications
  En tant que mainteneur du projet
  Je veux que les caractéristiques du site soient vérifiées

  Scénario: Le site répond sur la page d'accueil
    Soit je suis sur la page d'accueil du site
    Alors le code de statut de la réponse devrait être 200

  Scénario: Le nom du site apparaît dans le signet
    Soit je suis sur la page d'accueil du site
    Alors le titre de la page devrait contenir "symfony base"

  Scénario: Le favicon du site est identique à public/favicon.png
    Soit je suis sur la page d'accueil du site
    Alors le favicon du site devrait être identique à "public/favicon.png"

  Scénario: La locale par défaut du site est fr_CH
    Soit je suis sur la page d'accueil du site
    Alors la locale de la page devrait être "fr_CH"

  Scénario: Les feuilles de style utilisent Tailwind CSS
    Soit je suis sur la page d'accueil du site
    Alors le site devrait utiliser Tailwind CSS

  Scénario: Les assets sont gérés par Symfony Reprise et Vite
    Soit je suis sur la page d'accueil du site
    Alors les assets devraient être servis depuis le répertoire "/build/"

  Scénario: Le site exige PHP 8.4 ou supérieur
    Alors la version de PHP devrait être au moins "8.4"

  Scénario: Le site exige Symfony 8.1 ou supérieur
    Alors la version de Symfony devrait être au moins "8.1"