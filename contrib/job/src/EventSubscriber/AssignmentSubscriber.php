<?php

namespace Drupal\task_job\EventSubscriber;

use Drupal\task\Event\SelectAssigneeEvent;
use Drupal\task\Event\TaskEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies a job's assignment rule before the service-level fallback.
 */
class AssignmentSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [TaskEvents::SELECT_ASSIGNEE => ['selectAssignee', 100]];
  }

  /**
   * Selects an active account without replacing an explicit task assignment.
   */
  public function selectAssignee(SelectAssigneeEvent $event): void {
    $task = $event->getTask();
    $job = $task->job->entity;
    if (!$job || $job->get('assignment') === 'service_manager') {
      return;
    }
    if ($job->get('assignment') === 'creator') {
      $creator = $task->creator->entity;
      if ($creator && $creator->isAuthenticated() && $creator->isActive()) {
        $event->setAssignee($creator);
      }
    }
    // "Unassigned" (and an unknown rule) must not fall through to the service
    // manager. Other policies can be supplied by higher-priority subscribers.
    $event->stopPropagation();
  }

}
