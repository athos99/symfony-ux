# language: fr

@style
Fonctionnalité: Style visuel
  Afin de proposer une interface chaleureuse et lisible
  En tant que visiteur
  Je veux que le fond du site soit un beige très clair et que le site soit adapté à tous les écrans

  Scénario: Le corps de la page utilise la couleur de base du thème
    Soit je suis sur la page d'accueil du site
    Alors le corps de la page doit utiliser la couleur de base du thème

  Scénario: La couleur de base du thème est un beige très clair
    Soit je suis sur la page d'accueil du site
    Alors la couleur de fond doit être un beige très clair

  Scénario: Le site est adapté aux petits écrans
    Soit je suis sur la page d'accueil du site
    Alors la page devrait déclarer une balise viewport adaptée au responsive