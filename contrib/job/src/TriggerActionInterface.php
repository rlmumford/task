<?php

namespace Drupal\task_job;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface;

/**
 * Runs a trigger effect independently of the source event's matching logic.
 */
interface TriggerActionInterface extends ContextAwarePluginInterface, ConfigurableInterface, PluginFormInterface {

  /**
   * Executes this action and returns any newly created tasks.
   *
   * With $save FALSE, creation may return unsaved tasks; mutation actions must
   * not write. Existing task mutations are not returned as newly created tasks.
   *
   * @return \Drupal\task\TaskInterface[]
   *   Newly created tasks, saved only when requested.
   */
  public function execute(JobTriggerInterface $trigger, bool $save): array;

}
