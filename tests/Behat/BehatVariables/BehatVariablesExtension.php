<?php

declare(strict_types=1);

namespace App\Tests\Behat\BehatVariables;

use Behat\Behat\Transformation\ServiceContainer\TransformationExtension;
use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Enregistre le transformateur d'arguments `<<NOM>>` dans le conteneur Behat.
 */
final class BehatVariablesExtension implements Extension
{
    public function getConfigKey(): string
    {
        return 'behat_variables';
    }

    public function initialize(ExtensionManager $extensionManager): void
    {
    }

    public function configure(ArrayNodeDefinition $builder): void
    {
    }

    public function load(ContainerBuilder $container, array $config): void
    {
        $definition = new Definition(BehatVariablesArgumentTransformer::class);
        $definition->addTag(TransformationExtension::ARGUMENT_TRANSFORMER_TAG, ['priority' => 100]);

        $container->setDefinition('behat_variables.argument_transformer', $definition);
    }

    public function process(ContainerBuilder $container): void
    {
    }
}
