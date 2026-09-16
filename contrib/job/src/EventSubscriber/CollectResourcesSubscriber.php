<?php

namespace Drupal\task_job\EventSubscriber;

use Drupal\task\Event\CollectResourcesEvent;
use Drupal\task\Event\TaskEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber for collecting task resources.
 *
 * @package Drupal\task_job\EventSubscriber
 */
class CollectResourcesSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    $events[TaskEvents::COLLECT_RESOURCES] = 'collectResources';
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

}
