<?php

namespace Drupal\task_dependency;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Dependency records inherit their owning task's access.
 */
class DependencyAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entities;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $handler = new static($entity_type);
    $handler->entities = $container->get('entity_type.manager');
    return $handler;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation !== 'view') {
      // Configuration changes go through the owning task's editor; match state
      // is written only by workflow services, never generic entity endpoints.
      return AccessResult::forbidden();
    }
    $tasks = $this->entities->getStorage('task')->loadByProperties(['uuid' => $entity->get('owner')->value]);
    $task = reset($tasks);
    if (!$task) {
      return AccessResult::forbidden();
    }
    $access = $task->access('view', $account, TRUE)->addCacheableDependency($task)->addCacheableDependency($entity);
    foreach ($entity->get('bindings') as $binding) {
      $target = $this->entities->getStorage($binding->entity_type)->load($binding->entity_id);
      if (!$target || $target->uuid() !== $binding->entity_uuid) {
        return AccessResult::forbidden()->setCacheMaxAge(0);
      }
      $access = $access->andIf($target->access('view', $account, TRUE))->addCacheableDependency($target);
      if (str_starts_with($entity->get('trigger')->value, 'entity.state:')) {
        $field = $entity->get('configuration')->first()->getValue()['field'];
        if (!$target->hasField($field)) {
          return AccessResult::forbidden()->setCacheMaxAge(0);
        }
        $access = $access->andIf($target->get($field)->access('view', $account, TRUE));
      }
    }
    return $access;
  }

}
