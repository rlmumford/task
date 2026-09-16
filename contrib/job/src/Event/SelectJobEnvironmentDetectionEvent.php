<?php

namespace Drupal\task_job\Event;

use Drupal\Core\Session\AccountInterface;
use Drupal\exec_environment\Event\EnvironmentDetectionEvent;

/**
 * Event class for selecting jobs.
 *
 * @package Drupal\task_job\Event
 */
class SelectJobEnvironmentDetectionEvent extends EnvironmentDetectionEvent {

  /**
   * The assignee of the new task.
   *
   * @var \Drupal\Core\Session\AccountInterface|null
   */
  protected $assignee;

  /**
   * SelectJobEnvironmentDetectionEvent constructor.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $assignee
   *   The target assignee.
   */
  public function __construct(?AccountInterface $assignee = NULL) {
    $this->assignee = $assignee;
  }

  /**
   * Get the assignee of the new task.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   The assignee or null if none specified.
   */
  public function getAssignee() : ?AccountInterface {
    return $this->assignee;
  }

}
