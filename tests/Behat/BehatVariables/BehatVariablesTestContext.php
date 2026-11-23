<?php

declare(strict_types=1);

namespace App\Tests\Behat\BehatVariables;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;

/**
 * Étapes de test pour vérifier le stockage et la substitution des variables `<<NOM>>`.
 */
final class BehatVariablesTestContext implements Context
{
    #[Given('a value :value')]
    public function aValue(string $value): string
    {
        return $value;
    }

    /**
     * @return list<string>
     */
    #[Given('values :first and :second')]
    public function values(string $first, string $second): array
    {
        return [$first, $second];
    }

    #[Given('a pystring')]
    public function aPyString(PyStringNode $pyString): string
    {
        return $pyString->getRaw();
    }

    #[Given('a table')]
    public function aTable(TableNode $table): string
    {
        return $table->getRow(1)[1];
    }

    #[Then('the variable :name should be :expected')]
    public function theVariableShouldBe(string $name, string $expected): void
    {
        $actual = (string) BehatVariablesDatabase::get($name);

        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf('La variable "%s" vaut "%s", "%s" attendu.', $name, $actual, $expected));
        }
    }

    #[Then('the variable :name should not exist')]
    public function theVariableShouldNotExist(string $name): void
    {
        if (BehatVariablesDatabase::has($name)) {
            throw new \RuntimeException(sprintf('La variable "%s" ne devrait pas exister.', $name));
        }
    }
}
