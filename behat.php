<?php

declare(strict_types=1);

use App\Tests\Behat\BehatVariables\BehatVariablesContext;
use App\Tests\Behat\BehatVariables\BehatVariablesExtension;
use App\Tests\Behat\BehatVariables\BehatVariablesTestContext;
use App\Tests\Behat\Context\SiteContext;
use App\Tests\Behat\FeatureContext;
use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Behat\MinkExtension\ServiceContainer\MinkExtension;
use DVDoug\Behat\CodeCoverage\Extension as CodeCoverageExtension;
use Symfony\Component\Dotenv\Dotenv;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(__DIR__.'/.env');

return (new Config())
    ->withProfile(
        (new Profile('default'))
            ->withSuite(
                (new Suite('default'))
                    ->withPaths('%paths.base%/features')
                    ->withContexts(BehatVariablesContext::class, FeatureContext::class, SiteContext::class),
            )
            ->withSuite(
                (new Suite('behat-variables'))
                    ->withPaths('%paths.base%/tests/Behat/BehatVariables/features')
                    ->withContexts(BehatVariablesContext::class, BehatVariablesTestContext::class),
            )
            ->withExtension(
                new Extension(BehatVariablesExtension::class),
            )
            ->withExtension(
                new Extension(MinkExtension::class, [
                    'base_url' => 'http://localhost',
                    'sessions' => [
                        'default' => [
                            'browserkit_http' => null,
                        ],
                    ],
                ]),
            )
            ->withExtension(
                new Extension(CodeCoverageExtension::class, [
                    'filter' => [
                        'include' => [
                            'directories' => [
                                'src' => ['suffix' => '.php'],
                            ],
                        ],
                    ],
                    'reports' => [
                        'html' => ['target' => 'var/coverage/behat'],
                        'text' => ['showOnlySummary' => true],
                    ],
                ]),
            ),
    );
