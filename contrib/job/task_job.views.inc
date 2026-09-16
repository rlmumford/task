<?php

/**
 * @file
 * Views integration for task jobs.
 */

/**
 * Implements hook_views_data_alter().
 */
function task_job_views_data_alter(&$data) {
  /** @var \Drupal\views\Plugin\ViewsHandlerManager $filter_manager */
  $filter_manager = \Drupal::service('plugin.manager.views.filter');

  if ($filter_manager->hasDefinition('entity_reference')) {
    $data['task']['job']['filter']['id'] = 'entity_reference';
  }
}
