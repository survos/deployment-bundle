<?php

namespace Survos\DeploymentBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;

// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
#[RequiredBundle(SurvosKitBundle::class)]
class SurvosDeploymentBundle extends AbstractSurvosBundle
{
    // src/Command/ is auto-scanned by AbstractSurvosBundle (autowire + autoconfigure,
    // no manual tagging) — this is what DokkuCommand and DokkuCommands both need.
    // DokkuCommands went unregistered for a while under the old raw-AbstractBundle
    // wiring (a class-level 'console.command' tag doesn't work for method-level
    // #[AsCommand] — see git history); this convention removes that whole class of bug.

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
            ->end();
    }
}
