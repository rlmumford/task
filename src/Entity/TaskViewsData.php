<?php

namespace Drupal\task\Entity;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\entity\EntityViewsData;

/**
 * Build the views data for tasks.
 *
 * @package Drupal\task\Entity
 */
class TaskViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  protected function mapSingleFieldViewsData(
    $table,
    $field_name,
    $field_type,
    $column_name,
    $column_type,
    $first,
    FieldDefinitionInterface $field_definition
  ) {
    $data = parent::mapSingleFieldViewsData(
      $table,
      $field_name,
      $field_type,
      $column_name,
      $column_type,
      $first,
      $field_definition
    );

    $filter_manager = \Drupal::service('plugin.manager.views.filter');

    if (
      $field_type === 'entity_reference' &&
      $filter_manager->hasDefinition('entity_reference')
    ) {
      $data['filter']['id'] = 'entity_reference';
    }

    return $data;
  }

}
