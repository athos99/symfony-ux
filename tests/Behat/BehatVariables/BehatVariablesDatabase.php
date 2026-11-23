<?php

declare(strict_types=1);

namespace App\Tests\Behat\BehatVariables;

/**
 * Stocke les variables de test partagées entre les étapes d'un scénario.
 */
final class BehatVariablesDatabase
{
    /**
     * @var array<string, scalar>
     */
    private static array $storage = [];

    public static function set(string $name, mixed $value): void
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException(sprintf('La valeur stockée doit être scalaire, "%s" reçu.', get_debug_type($value)));
        }

        self::$storage[$name] = $value;
    }

    public static function get(string $name): string|int|float|bool
    {
        if (!array_key_exists($name, self::$storage)) {
            throw new \RuntimeException(sprintf('La variable "%s" n\'existe pas.', $name));
        }

        return self::$storage[$name];
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::$storage);
    }

    /**
     * @return array<string, scalar>
     */
    public static function all(): array
    {
        return self::$storage;
    }

    public static function clear(): void
    {
        self::$storage = [];
    }
}
