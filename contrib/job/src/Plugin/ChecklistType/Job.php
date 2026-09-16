<?php

namespace Drupal\task_job\Plugin\ChecklistType;

use Drupal\checklist\ChecklistInterface;
use Drupal\checklist\Plugin\ChecklistType\ChecklistTypeBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\Plugin\PluginWithFormsTrait;
use Drupal\task\Entity\Task;
use Drupal\task_job\JobInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Job checklist types.
 *
 * @ChecklistType(
 *   id = "job",
 *   label = @Translation("Job Checklist"),
 *   entity_type = "task",
 *   forms = {
 *     "complete" = "Drupal\task_job\PluginForm\JobChecklistCompleteForm",
 *   }
 * )
 *
 * @package Drupal\task_job\Plugin\ChecklistType
 */
class Job extends ChecklistTypeBase implements PluginWithFormsInterface {
  use PluginWithFormsTrait;

  /**
   * The job storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $jobStorage;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')->getStorage('checklist_item'),
      $container->get('event_dispatcher'),
      $container->get('entity_type.manager')->getStorage('task_job')
    );
  }

  /**
   * Job constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $item_storage
   *   The checklist item entity storage.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   * @param \Drupal\Core\Entity\EntityStorageInterface $job_storage
   *   The job storage.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityStorageInterface $item_storage,
    EventDispatcherInterface $event_dispatcher,
    EntityStorageInterface $job_storage
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $item_storage, $event_dispatcher);

    $this->jobStorage = $job_storage;
  }

  /**
   * Get the job.
   *
   * We return a Job interface here, rather than a Job entity in anticipation
   * of some complex system of Job overides.
   *
   * @return \Drupal\task_job\JobInterface|null
   *   The job.
   */
  public function getJob() : ?JobInterface {
    return $this->configuration['job'] instanceof JobInterface ?
      $this->configuration['job'] :
      ($this->jobStorage->load($this->configuration['job']) ?? NULL);
  }

  /**
   * Get the default items.
   *
   * @return \Drupal\checklist\Entity\ChecklistItemInterface[]
   *   The default checklist items.
   */
  public function getDefaultItems() : array {
    if (isset($this->configuration['default_items']) && is_array($this->configuration['default_items'])) {
      return parent::getDefaultItems();
    }

    $items = [];

    if ($job = $this->getJob()) {
      foreach ($job->getChecklistItems() as $name => $config) {
        $items[$name] = $this->itemStorage()->create(
          [
            'checklist_type' => $this->getPluginId(),
            'name' => $name,
            'title' => $config['label'],
            'handler' => [
              'id' => $config['handler'],
              'configuration' => $config['handler_configuration'],
            ],
          ]
        );
      }
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function isChecklistComplete(ChecklistInterface $checklist): bool {
    /** @var \Drupal\task\Entity\Task $task */
    $task = $checklist->getEntity();
    return $task->status->value == Task::STATUS_RESOLVED || $task->status->value == Task::STATUS_CLOSED;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    $config = parent::getConfiguration();

    // Ensure job object is downcasted.
    if ($config['job'] instanceof JobInterface) {
      $config['job'] = $config['job']->id();
    }

    return $config;
  }

}
