<?php

namespace Drupal\task\Plugin\views\argument_validator;

use Drupal\views\Plugin\views\argument_validator\Entity;

/**
 * Validator that gets the root task from the task passed.
 *
 * @ViewsArgumentValidator(
 *   id = "root_task",
 *   title = @Translation("Root Task"),
 *   entity_type = "task"
 * )
 *
 * @package Drupal\task\Plugin\views\argument_validator
 */
class RootTask extends Entity {

  /**
   * {@inheritdoc}
   */
  public function validateArgument($argument) {
    $entity_type = $this->definition['entity_type'];

    if ($this->multipleCapable && $this->options['multiple']) {
      // At this point only interested in individual IDs no matter what type,
      // just splitting by the allowed delimiters.
      $ids = array_filter(preg_split('/[,+ ]/', $argument));
    }
    elseif ($argument) {
      $ids = [$argument];
    }
    // No specified argument should be invalid.
    else {
      return FALSE;
    }

    $entities = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($ids);
    // Validate each id => entity. If any fails break out and return false.
    foreach ($ids as &$id) {
      // There is no entity for this ID.
      if (!isset($entities[$id])) {
        return FALSE;
      }

      $root = $entities[$id]->root->isEmpty() ? $entities[$id] : $entities[$id]->root->entity;
      $id = $root->id();
      if (!$this->validateEntity($root)) {
        return FALSE;
      }
    }

    $this->argument->argument = implode('+', array_unique($ids));
    return TRUE;
  }

}
