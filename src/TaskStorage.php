<?php

namespace Drupal\task;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\task\Event\SelectAssigneeEvent;
use Drupal\task\Event\TaskEvents;

/**
 * Task storage handler.
 */
class TaskStorage extends SqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  public function doPreSave(EntityInterface $entity) {
    $id = parent::doPreSave($entity);

    if (!$entity->assignee->entity) {
      $event = new SelectAssigneeEvent($entity);

      /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
      $dispatcher = \Drupal::service('event_dispatcher');
      $dispatcher->dispatch($event, TaskEvents::SELECT_ASSIGNEE);

      if ($event->getAssignee()) {
        $entity->assignee = $event->getAssignee();
      }
    }

    if ($id && $entity->root->isEmpty()) {
      $entity->root = $id;
    }

    return $id;
  }

  /**
   * {@inheritdoc}
   */
  protected function doSaveFieldItems(ContentEntityInterface $entity, array $names = []) {
    // Normalize after all presave hooks, including direct status changes.
    $previous_time = $entity->get('resolved')->value;
    $status = $entity->get('status')->value;
    if ($status === TaskInterface::STATUS_RESOLVED && $entity->get('resolved')->isEmpty()) {
      $entity->set('resolved', gmdate(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, \Drupal::time()->getCurrentTime()));
    }
    elseif (!in_array($status, [TaskInterface::STATUS_RESOLVED, TaskInterface::STATUS_CLOSED], TRUE)) {
      $entity->set('resolved', NULL);
    }
    if ($names && $previous_time !== $entity->get('resolved')->value) {
      $names[] = 'resolved';
    }

    // Storage validators run inside the transaction, after all presave hooks.
    $this->moduleHandler()->invokeAll('task_storage_prewrite', [$entity]);
    parent::doSaveFieldItems($entity, $names);

    if ($entity->root->isEmpty()) {
      $entity->root = $entity->id();
      parent::doSaveFieldItems($entity, ['root']);
    }
  }

  /**
   * {@inheritdoc}
   *
   * We override this so that we handle set values first THEN handle default
   * values, this allows default values to depend on the existence of other
   * values.
   */
  protected function initFieldValues(ContentEntityInterface $entity, array $values = [], array $field_names = []) {
    // First set any supplied values.
    foreach ($values as $name => $value) {
      if (!$field_names || isset($field_names[$name])) {
        $entity->{$name} = $value;
      }
    }

    // Next apply any default values.
    foreach ($entity as $name => $field) {
      if (
        (!$field_names || isset($field_names[$name])) &&
        $entity->hasField($name) &&
        !isset($values[$name])
      ) {
        $entity->get($name)->applyDefaultValue();
      }
    }

    // Make sure modules can alter field initial values.
    $this->invokeHook('field_values_init', $entity);
  }

}
