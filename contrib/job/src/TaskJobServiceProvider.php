<?php

namespace Drupal\task_job;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\task_job\Plugin\JobTrigger\EnvironmentAwareJobTriggerManager;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Service provider to alter key definitions if exec_environment is enabled.
 *
 * @package Drupal\task_job
 */
class TaskJobServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if (array_key_exists('exec_environment', $container->getParameter('container.modules'))) {
      $definition = $container->getDefinition('plugin.manager.task_job.trigger');
      $definition->setClass(EnvironmentAwareJobTriggerManager::class);
      $definition->addMethodCall('setEnvironmentStack', [new Reference('environment_stack')]);
      $definition->addMethodCall('setConfigFactory', [new Reference('config.factory')]);
      $definition->addMethodCall('setEventDispatcher', [new Reference('event_dispatcher')]);
    }
  }

}
