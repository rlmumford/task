<?php

namespace Drupal\task\Event;

use Drupal\task\Entity\Task;
use Drupal\user\Entity\User;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event called to select an assignee.
 *
 * @package Drupal\task\Event
 */
class SelectAssigneeEvent extends Event {

  /**
   * The task.
   *
   * @var \Drupal\task\Entity\Task
   */
  protected $task;

  /**
   * The assignee selected.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $assignee;

  /**
   * SelectAssigneeEvent constructor.
   *
   * @param \Drupal\task\Entity\Task $task
   *   The task.
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
  public function getTask() {
    return $this->task;
  }

  /**
   * Set the assignee.
   *
   * @param \Drupal\user\Entity\User $assignee
   *   The user to set as the assignee.
   */
  public function setAssignee(User $assignee) {
    $this->assignee = $assignee;
  }

  /**
   * Get the assignee currently selected.
   *
   * @return \Drupal\user\Entity\User
   *   The assignee that has been selected.
   */
  public function getAssignee() {
    return $this->assignee;
  }

}
