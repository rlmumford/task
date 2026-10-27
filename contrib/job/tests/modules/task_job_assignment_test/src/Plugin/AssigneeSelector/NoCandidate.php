<?php

namespace Drupal\task_job_assignment_test\Plugin\AssigneeSelector;

use Drupal\task\TaskInterface;
use Drupal\task_job\AssigneeSelectorBase;
use Drupal\user\UserInterface;

/**
 * An installed selector can intentionally leave work unassigned.
 *
 * @AssigneeSelector(id = "test_no_candidate", label = @Translation("No candidate"))
 */
final class NoCandidate extends AssigneeSelectorBase {

  /**
   * {@inheritdoc}
   */
  public function select(TaskInterface $task): ?UserInterface {
    return NULL;
  }

}
