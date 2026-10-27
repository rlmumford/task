<?php

namespace Drupal\task_job;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\task\TaskInterface;
use Drupal\user\UserInterface;

/**
 * Selects one user for a matched job rule during task persistence.
 */
interface AssigneeSelectorInterface extends ContextAwarePluginInterface, ConfigurableInterface, PluginFormInterface {

  /**
   * Selects within the task save transaction; NULL leaves work unassigned.
   *
   * Implementations must not change existing assignees or save the task.
   */
  public function select(TaskInterface $task): ?UserInterface;

}
