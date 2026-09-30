<?php

namespace Drupal\task_dependency\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * A UUID-identified event subscription owned by one task.
 *
 * @ContentEntityType(
 *   id = "task_dependency",
 *   label = @Translation("Task dependency"),
 *   base_table = "task_dependency",
 *   handlers = {
 *     "storage" = "Drupal\task_dependency\DependencyStorage",
 *     "storage_schema" = "Drupal\task_dependency\DependencyStorageSchema",
 *     "access" = "Drupal\task_dependency\DependencyAccessControlHandler"
 *   },
 *   entity_keys = {"id" = "uuid", "uuid" = "uuid"}
 * )
 */
class Dependency extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['owner'] = BaseFieldDefinition::create('string')->setSetting('max_length', 36)->setLabel(t('Owning task UUID'))->setRequired(TRUE);
    $fields['trigger'] = BaseFieldDefinition::create('string')->setLabel(t('Trigger'))->setRequired(TRUE);
    $fields['configuration'] = BaseFieldDefinition::create('map')->setLabel(t('Trigger configuration'));
    $fields['action'] = BaseFieldDefinition::create('list_string')->setLabel(t('Action'))
      ->setSetting('allowed_values', ['activate' => 'Activate', 'invalidate' => 'Invalidate'])->setDefaultValue('activate');
    $fields['bindings'] = BaseFieldDefinition::create('task_dependency_binding')->setLabel(t('Context bindings'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $fields['met'] = BaseFieldDefinition::create('boolean')->setLabel(t('Met'))->setDefaultValue(FALSE)->setReadOnly(TRUE);
    $fields['occurrence'] = BaseFieldDefinition::create('string')->setSetting('max_length', 36)->setLabel(t('Matching occurrence'))->setReadOnly(TRUE);
    $fields['met_at'] = BaseFieldDefinition::create('timestamp')->setLabel(t('Matched at'))->setReadOnly(TRUE);
    return $fields;
  }

}
