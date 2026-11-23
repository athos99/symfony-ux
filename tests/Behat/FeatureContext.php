<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Behat\Context\Context;
use Behat\MinkExtension\Context\MinkContext;

/**
 * Contexte Behat pour les tests fonctionnels Symfony (driver BrowserKit, sans serveur HTTP réel).
 */
final class FeatureContext extends MinkContext implements Context
{
}
