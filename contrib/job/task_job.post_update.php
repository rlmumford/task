<?php

/**
 * @file
 * Post Updates for the task_job module.
 */

/**
 * Index the job triggers on jobs.
 */
function task_job_post_update_index_job_triggers() {
  $job_storage = \Drupal::entityTypeManager()->getStorage('task_job');
  $query = \Drupal::database()->insert('task_job_trigger_index');
  $query->fields(['job', 'trigger', 'trigger_key']);

  /** @var \Drupal\task_job\Entity\Job $job */
  foreach ($job_storage->loadMultiple(NULL) as $job) {
    foreach ($job->getTriggersConfiguration() as $key => $config) {
      $query->values([
        'job' => $job->id(),
        'trigger' => $config['id'],
        'trigger_key' => $key,
      ]);
    }
  }

  $query->execute();
}
