<?php

namespace Drupal\task_job\EventSubscriber;

use Drupal\checklist\ChecklistActionResource;
use Drupal\checklist\Event\ChecklistCollectResourcesEvent;
use Drupal\checklist\Event\ChecklistEvents;
use Drupal\task\Event\CollectResourcesEvent;
use Drupal\task\Event\TaskEvents;
use Drupal\task\TaskInterface;
use Drupal\task\TaskResourceManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber for collecting task resources.
 *
 * @package Drupal\task_job\EventSubscriber
 */
class CollectResourcesSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\task\TaskResourceManagerInterface $resourceManager
   *   Builds task resources with their configured contexts and access checks.
   */
  public function __construct(
    protected TaskResourceManagerInterface $resourceManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    $events[TaskEvents::COLLECT_RESOURCES] = 'collectResources';
    $events[ChecklistEvents::COLLECT_RESOURCES] = 'collectChecklistResources';
    return $events;
  }

  /**
   * Collect resources from the job configuration.
   *
   * @param \Drupal\task\Event\CollectResourcesEvent $event
   *   The event.
   */
  public function collectResources(CollectResourcesEvent $event) {
    /** @var \Drupal\task_job\JobInterface $job */
    $job = $event->getTask()->job->entity;
    if (!$job) {
      return;
    }

    foreach ($job->getResourcesConfiguration() as $key => $configuration) {
      $event->addResource("job__{$key}", $configuration['id'], $configuration);
    }
  }

  /**
   * Adds task resources to the checklist resource pane.
   *
   * @param \Drupal\checklist\Event\ChecklistCollectResourcesEvent $event
   *   The checklist resource collection event.
   */
  public function collectChecklistResources(ChecklistCollectResourcesEvent $event): void {
    $task = $event->getChecklist()->getEntity();
    if (!$task instanceof TaskInterface) {
      return;
    }

    foreach ($this->resourceManager->buildTaskResources($task) as $key => $build) {
      $plugin = $build['#block_plugin'] ?? NULL;
      $label = $plugin ? $plugin->label() : ($build['#configuration']['label'] ?? $build['#plugin_id'] ?? $key);
      $event->addResource(new ChecklistActionResource(
        'task__' . $key,
        $build,
        $label,
        $build['#weight'] ?? 0,
      ));
    }
  }

}
