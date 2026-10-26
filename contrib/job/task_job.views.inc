<?php

/**
 * @file
 * Views integration for task jobs.
 */

/**
 * Implements hook_views_data_alter().
 */
function task_job_views_data_alter(&$data) {
  // Preserve the field name used by existing task boards after expanding its
  // storage to multiple columns. No fixed-target entity reference handler fits.
  $data['task']['job'] = $data['task']['job__target_id'];
  $data['task']['job']['real field'] = 'job__target_id';
}
