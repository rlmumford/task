<?php

namespace Drupal\task;

use Drupal\Core\Session\AccountInterface;
use Drupal\entity\QueryAccess\ConditionGroup;
use Drupal\entity\QueryAccess\QueryAccessHandlerBase;

/**
 * Query access handler for tasks.
 *
 * @package Drupal\task
 */
class TaskQueryAccessHandler extends QueryAccessHandlerBase {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityConditions($operation, AccountInterface $account) {
    if ($operation === 'view') {
      $conditions = new ConditionGroup('OR');
      $conditions->addCacheContexts(['user.permissions']);

      if ($account->hasPermission("{$operation} any tasks")) {
        return $conditions;
      }

      if ($account->hasPermission("{$operation} assigned tasks")) {
        $conditions->addCacheContexts(['user']);
        $conditions->addCondition('assignee', $account->id());
      }

      return $conditions->count() ? $conditions : NULL;
    }

    return parent::buildEntityConditions($operation, $account);
  }

}
