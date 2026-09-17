<?php

/**
 * @file
 * Hooks provided by the task module.
 */

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Validates final task fields immediately before storage writes them.
 *
 * Runs after presave hooks, within the SQL storage transaction.
 * Implementations may acquire database locks and throw to abort a save.
 * Do not mutate the entity or perform external side effects. Validation
 * may run more than once if field post-save handlers request another write.
 *
 * @param \Drupal\Core\Entity\ContentEntityInterface $task
 *   The task being written.
 */
function hook_task_storage_prewrite(ContentEntityInterface $task) {
  \Drupal::service('example.task_validator')->validate($task);
}
