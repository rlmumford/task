<?php

namespace Drupal\task\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-evaluates scheduled tasks when their start time arrives.
 *
 * @QueueWorker(
 *   id = "task_scheduled",
 *   title = @Translation("Activate scheduled tasks"),
 *   cron = {"time" = 15}
 * )
 */
class ScheduledTask extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the worker.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $task = $this->entityTypeManager->getStorage('task')->load($data);
    if ($task && $task->status->value === 'pending') {
      $task->save();
    }
  }

}
