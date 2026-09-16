<?php

namespace Drupal\task_checklist;

use Drupal\task\Entity\Task;

/**
 * Interface for the task checklist processor service.
 *
 * @package Drupal\task_checklist
 */
interface TaskChecklistProcessorInterface {

  /**
   * Process a task checklist.
   *
   * @param \Drupal\task\Entity\Task $task
   *   The task to process.
   */
  public function processTask(Task $task);

}
