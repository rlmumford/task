<?php

namespace Drupal\task;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for task entities.
 */
class TaskAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return parent::checkCreateAccess($account, $context, $entity_bundle)
      ->orIf(AccessResult::allowedIfHasPermission($account, 'create tasks'));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    return parent::checkAccess($entity, $operation, $account)
      ->orIf(AccessResult::allowedIfHasPermission($account, "{$operation} any tasks"))
      ->orIf(
        AccessResult::allowedIfHasPermission($account, "{$operation} assigned tasks")
          ->andIf(
            AccessResult::allowedIf($account->isAuthenticated() && (string) $entity->assignee->target_id === (string) $account->id())
              ->cachePerUser()
              ->addCacheableDependency($entity)
          )
      );
  }

}
