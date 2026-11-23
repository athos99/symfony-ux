# language: fr

@behat-variables
Fonctionnalité: Réinitialisation des variables Behat
  Afin d'éviter les fuites de variables entre fonctionnalités
  En tant qu'auteur de tests
  Je veux que les variables soient effacées en fin de fonctionnalité

  Scénario: Les variables de la fonctionnalité précédente sont effacées
    Alors the variable "MA_VALEUR" should not exist
    Et the variable "USER" should not exist