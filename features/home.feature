# language: fr

@home
Fonctionnalité: Page d'accueil
  Afin de vérifier que le site répond correctement
  En tant que visiteur
  Je veux accéder à la page d'accueil

  Scénario: Affichage de la page d'accueil
    Soit je suis sur la page d'accueil du site
    Alors le code de statut de la réponse devrait être 200
    Et le corps de la page devrait contenir "Test Stimulus"
    Et le corps de la page devrait contenir "Test Tailwind"