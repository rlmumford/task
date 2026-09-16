<?php

namespace Drupal\task_job\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity\EntityAccessControlHandler;

/**
 * Access control handler for job entities.
 *
 * @package Drupal\task_job\Entity
 */
class JobAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\task_job\Entity\Job $entity */
    if ($operation === 'enable' && $entity->status()) {
      return AccessResult::forbidden('Already Enabled')
        ->addCacheableDependency($entity);
    }

    if ($operation === 'disable' && !$entity->status()) {
      return AccessResult::forbidden('Already Disabled')
        ->addCacheableDependency($entity);
    }

    if (in_array($operation, ['enable', 'disable'])) {
      $operation = 'update';
    }

    return parent::checkAccess($entity, $operation, $account);
  }

}
