<?php

namespace Drupal\task_dependency;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Indexed dependency queries, isolated from consumers and schedulers.
 */
class DependencyStorage extends SqlContentEntityStorage {

  /**
   * Loads subscriptions whose binding references this exact target.
   */
  public function watching(string $type, string $id): array {
    $query = $this->getQuery()->accessCheck(FALSE);
    $query->condition($query->andConditionGroup()
      ->condition('bindings.entity_type', $type)
      ->condition('bindings.entity_id', $id));
    return $this->loadMultiple($query->execute());
  }

}
