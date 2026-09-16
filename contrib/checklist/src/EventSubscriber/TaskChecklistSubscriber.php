<?php

namespace Drupal\task_checklist\EventSubscriber;

use Drupal\checklist\Event\ChecklistEvent;
use Drupal\task\Entity\Task;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribe to task checklist events.
 */
class TaskChecklistSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events['checklist.complete.task.checklist'] = 'onTaskChecklistComplete';
    return $events;
  }

  /**
   * Act when the task checklist is completed.
   *
   * @param \Drupal\checklist\Event\ChecklistEvent $event
   *   The checklist event.
   */
  public function onTaskChecklistComplete(ChecklistEvent $event) {
    /** @var \Drupal\task\Entity\Task $task */
    $task = $event->getChecklist()->getEntity();
    $task->resolve(Task::RESOLUTION_COMPLETE)->save();
  }

}
