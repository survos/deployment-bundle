<?php

/** generated from /home/tac/g/survos/survos/packages/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\DeploymentBundle;

use Survos\DeploymentBundle\Command\DokkuCommand;
use Survos\DeploymentBundle\Twig\TwigExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosDeploymentBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // $builder->setParameter('survos_workflow.direction', $config['direction']);

        // twig classes
//        $builder
//            ->autowire('survos.deployment_bundle', TwigExtension::class)
//            ->setArgument('$config', $config)
//            ->addTag('twig.extension');

        $builder->autowire(DokkuCommand::class)
            ->setAutoconfigured(true)
            ->addTag('console.command');

        /*
        $definition->setArgument('$widthFactor', $config['widthFactor']);
        $definition->setArgument('$height', $config['height']);
        $definition->setArgument('$foregroundColor', $config['foregroundColor']);
        */
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->end();
    }
}
