<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require __DIR__.'/../vendor/autoload.php';

$environment = $_SERVER['APP_ENV'] ?? 'dev';
if (!is_string($environment)) {
    $environment = 'dev';
}

$debug = $_SERVER['APP_DEBUG'] ?? false;

$kernel = new Kernel($environment, (bool) $debug);

return new Application($kernel);
