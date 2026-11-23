# language: fr

@behat-variables
Fonctionnalité: Variables Behat
  Afin de réutiliser des valeurs créées pendant un scénario
  En tant qu'auteur de tests
  Je veux stocker des valeurs dans des variables

  Scénario: Substitution d'une variable dans un argument scalaire
    Soit a value "42"
    Et I save it into "MA_VALEUR"
    Alors the variable "MA_VALEUR" should be "42"

  Scénario: Stockage de plusieurs valeurs à la fois
    Soit values "fred" and "42"
    Et I save those into "USER,UID"
    Alors the variable "USER" should be "fred"
    Et the variable "UID" should be "42"

  Scénario: Substitution d'une variable dans un bloc de texte
    Soit a value "42"
    Et I save it into "MA_VALEUR"
    Et a pystring
      """
      valeur = <<MA_VALEUR>>
      """
    Et I save it into "BLOC"
    Alors the variable "BLOC" should be "valeur = 42"

  Scénario: Substitution d'une variable dans une table
    Soit a value "42"
    Et I save it into "MA_VALEUR"
    Et a table
      | clé   | valeur          |
      | total | <<MA_VALEUR>>   |
    Et I save it into "TOTAL"
    Alors the variable "TOTAL" should be "42"