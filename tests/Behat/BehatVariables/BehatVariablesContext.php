<?php

declare(strict_types=1);

namespace App\Tests\Behat\BehatVariables;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Tester\Result\ExecutedStepResult;
use Behat\Hook\AfterFeature;
use Behat\Hook\AfterStep;
use Behat\Step\When;

/**
 * Contexte fournissant la sauvegarde et la substitution des variables `<<NOM>>`.
 */
final class BehatVariablesContext implements Context
{
    /**
     * @var list<scalar>
     */
    private array $lastResult = [];

    #[AfterStep]
    public function afterStep(AfterStepScope $scope): void
    {
        $this->lastResult = [];

        $result = $scope->getTestResult();

        if (!$result instanceof ExecutedStepResult) {
            return;
        }

        $return = $result->getCallResult()->getReturn();

        if (null === $return) {
            return;
        }

        $values = is_array($return) ? $return : [$return];

        foreach ($values as $value) {
            if (!is_scalar($value)) {
                throw new \RuntimeException(sprintf('Une étape doit retourner une valeur scalaire, "%s" reçu.', get_debug_type($value)));
            }
        }

        $this->lastResult = array_values($values);
    }

    #[AfterFeature]
    public static function afterFeature(AfterFeatureScope $scope): void
    {
        BehatVariablesDatabase::clear();
    }

    #[When('/^(?:I|we) save (?:it|that|those|them) into "([^"]+)"$/')]
    public function saveItInto(string $slots): void
    {
        if ([] === $this->lastResult) {
            throw new \RuntimeException('Impossible de stocker une valeur vide. Une étape doit retourner une valeur.');
        }

        $names = explode(',', $slots);

        if (count($names) !== count($this->lastResult)) {
            throw new \RuntimeException(sprintf('Le nombre de noms (%d) ne correspond pas au nombre de dernières valeurs (%d).', count($names), count($this->lastResult)));
        }

        foreach ($names as $index => $name) {
            if ('' === $name) {
                continue;
            }

            if (!BehatVariablesArgumentTransformer::isValidSlotName($name)) {
                throw new \RuntimeException(sprintf('Nom de variable invalide "%s". Attention aux espaces.', $name));
            }

            BehatVariablesDatabase::set($name, $this->lastResult[$index]);
        }

        $this->lastResult = [];
    }

    public function setVariable(string $slot, mixed $value): void
    {
        BehatVariablesDatabase::set($slot, $value);
    }

    public function getVariable(string $slot): mixed
    {
        return BehatVariablesDatabase::get($slot);
    }
}
