<?php

namespace Drupal\task_dependency\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\task_dependency\DependencyScheduler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rechecks task readiness after committed dependency changes.
 *
 * @QueueWorker(id = "task_dependency_reevaluate", title = @Translation("Task dependency reevaluation"), cron = {"time" = 15})
 */
class DependencyReevaluation extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the worker.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected DependencyScheduler $scheduler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('task_dependency.scheduler'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $this->scheduler->run($data);
  }

}
