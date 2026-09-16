<?php

namespace Drupal\task_checklist\Event;

use Drupal\exec_environment\Event\EnvironmentDetectionEvent;
use Drupal\task\Entity\Task;

/**
 * Event used for detecting environments when a checklist is about to run.
 *
 * @package Drupal\task_checklist\Event
 */
class TaskChecklistEnvironmentDetectionEvent extends EnvironmentDetectionEvent {

  /**
   * The task.
   *
   * @var \Drupal\task\Entity\Task
   */
  protected $task;

  /**
   * TaskChecklistEnvironmentDetectionEvent constructor.
   *
   * @param \Drupal\task\Entity\Task $task
   *   The task about to be processed.
   */
  public function __construct(Task $task) {
    $this->task = $task;
  }

  /**
   * Get the task.
   *
   * @return \Drupal\task\Entity\Task
   *   The task.
   */
  public function getTask() : Task {
    return $this->task;
  }

}
