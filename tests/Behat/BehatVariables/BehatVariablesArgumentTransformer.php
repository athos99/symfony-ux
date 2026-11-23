<?php

declare(strict_types=1);

namespace App\Tests\Behat\BehatVariables;

use Behat\Behat\Transformation\Scope\TransformationScope;
use Behat\Behat\Transformation\Transformer\ArgumentTransformer;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;

/**
 * Remplace les jetons `<<NOM>>` présents dans les arguments de n'importe quelle étape.
 */
final class BehatVariablesArgumentTransformer implements ArgumentTransformer
{
    private const string SLOT_NAME_REGEX = '#<<([a-z]\w*)>>#i';

    public function supportsDefinitionAndArgument(TransformationScope $scope, int|string $argumentIndex, mixed $argumentValue): bool
    {
        if ($argumentValue instanceof TableNode) {
            return $this->tableContainsSlot($argumentValue);
        }

        if ($argumentValue instanceof PyStringNode) {
            return $this->containsSlot($argumentValue->getRaw());
        }

        if (is_scalar($argumentValue)) {
            return $this->containsSlot((string) $argumentValue);
        }

        return false;
    }

    public function transformArgument(TransformationScope $scope, int|string $argumentIndex, mixed $argumentValue): mixed
    {
        if ($argumentValue instanceof TableNode) {
            return $this->transformTable($argumentValue);
        }

        if ($argumentValue instanceof PyStringNode) {
            return new PyStringNode(
                explode("\n", $this->replace($argumentValue->getRaw())),
                $argumentValue->getLine(),
            );
        }

        if (is_scalar($argumentValue)) {
            return $this->replace((string) $argumentValue);
        }

        return $argumentValue;
    }

    public static function isValidSlotName(string $slot): bool
    {
        return 1 === preg_match('/^[a-z]\w*$/i', $slot);
    }

    private function containsSlot(string $value): bool
    {
        return 1 === preg_match(self::SLOT_NAME_REGEX, $value);
    }

    private function tableContainsSlot(TableNode $table): bool
    {
        foreach ($table->getRows() as $row) {
            foreach ($row as $cell) {
                if ($this->containsSlot($cell)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function transformTable(TableNode $table): TableNode
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $newRow = [];

            foreach ($row as $cell) {
                $newRow[] = $this->replace($cell);
            }

            $rows[] = $newRow;
        }

        return new TableNode($rows);
    }

    private function replace(string $value): string
    {
        if (!preg_match_all(self::SLOT_NAME_REGEX, $value, $matches, PREG_SET_ORDER)) {
            return $value;
        }

        $replacements = [];

        foreach ($matches as $match) {
            $replacements[$match[0]] = (string) BehatVariablesDatabase::get($match[1]);
        }

        return strtr($value, $replacements);
    }
}
