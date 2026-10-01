<?php

namespace Drupal\task_job\Plugin\JobTriggerAction;

use Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface;
use Drupal\task_job\TriggerActionBase;

/**
 * Preserves the existing template-based task creation effect.
 *
 * @JobTriggerAction(id = "create_task", label = @Translation("Create a task"))
 */
class CreateTask extends TriggerActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(JobTriggerInterface $trigger, bool $save): array {
    $task = $trigger->createTask();
    if (!$task) {
      return [];
    }
    if ($save) {
      $task->save();
    }
    return [$task];
  }

}
