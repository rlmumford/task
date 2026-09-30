<?php

namespace Drupal\task_checklist\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\task_checklist\TaskChecklistRequestRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes committed requests to reevaluate a whole task checklist.
 *
 * @QueueWorker(
 *   id = "task_checklist_process",
 *   title = @Translation("Process task checklists"),
 *   cron = {"time" = 15}
 * )
 */
class TaskChecklist extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the worker.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected TaskChecklistRequestRunner $runner) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('task_checklist.request_runner'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $this->runner->run($data);
  }

}
