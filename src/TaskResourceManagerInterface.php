<?php

namespace Drupal\task;

/**
 * Interface for the task resource manager service.
 *
 * @package Drupal\task
 */
interface TaskResourceManagerInterface {

  /**
   * Build the render array for task resources.
   *
   * @param \Drupal\task\TaskInterface $task
   *   The task.
   *
   * @return array
   *   The build array.
   */
  public function buildTaskResources(TaskInterface $task) : array;

}
