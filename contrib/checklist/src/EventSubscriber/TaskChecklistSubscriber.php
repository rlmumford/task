<?php

namespace Drupal\task_checklist\EventSubscriber;

use Drupal\checklist\Event\ChecklistEvent;
use Drupal\task\Entity\Task;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribe to task checklist events.
 */
class TaskChecklistSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the completion subscriber.
   */
  public function __construct(private readonly EntityTypeManagerInterface $entities, private readonly Connection $database) {}

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
    if ($task->isNew()) {
      $task->resolve(Task::RESOLUTION_COMPLETE)->save();
      return;
    }
    // A browser checklist may contain a host snapshot from before assignment.
    // Resolution changes lifecycle fields; it must not save that old snapshot
    // over staff assignments or other edits made since the form was opened.
    $transaction = $this->database->startTransaction();
    try {
      $this->database->select('task', 't')->fields('t', ['id'])
        ->condition('id', $task->id())->forUpdate()->execute()->fetchField();
      $fresh = $this->entities->getStorage('task')->loadUnchanged($task->id());
      if (!$fresh || $fresh->uuid() !== $task->uuid()) {
        throw new \LogicException('The task no longer exists.');
      }
      $fresh->resolve(Task::RESOLUTION_COMPLETE)->save();
      foreach (['status', 'resolution', 'resolved', 'assignee'] as $field) {
        $task->set($field, $fresh->get($field)->getValue());
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    finally {
      unset($transaction);
    }
  }

}
