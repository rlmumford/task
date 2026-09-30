<?php

namespace Drupal\task_dependency\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Stores searchable, concrete context bindings, never serialized selectors.
 *
 * @FieldType(
 *   id = "task_dependency_binding",
 *   label = @Translation("Dependency context binding"),
 *   no_ui = TRUE
 * )
 */
class Binding extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = [];
    foreach (['context', 'entity_type', 'entity_id', 'entity_uuid'] as $name) {
      $properties[$name] = DataDefinition::create('string')->setLabel($name)->setRequired(TRUE);
    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'context' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
        'entity_type' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
        'entity_id' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE],
        'entity_uuid' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => TRUE],
      ],
      'indexes' => ['target' => ['entity_type', 'entity_id', 'context']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    return $this->get('entity_id')->getValue() === NULL;
  }

}
