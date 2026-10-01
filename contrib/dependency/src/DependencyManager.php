<?php

namespace Drupal\task_dependency;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\task\TaskInterface;
use Drupal\task_dependency\Entity\Dependency;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Shared dependency configuration, matching and replacement behavior.
 */
class DependencyManager {

  /**
   * Constructs the dependency manager.
   */
  public function __construct(protected EntityTypeManagerInterface $entities, protected TriggerManager $triggers, protected WorkflowStorage $workflow, protected Connection $database, protected UuidInterface $uuid, protected TimeInterface $time) {}

  /**
   * Returns a concrete, indexable binding for a saved entity.
   */
  public function bind(string $context, EntityInterface $entity): array {
    if ($entity->isNew() || !$entity->uuid()) {
      throw new \InvalidArgumentException('A dependency target must be saved and have a UUID.');
    }
    return [
      'context' => $context,
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (string) $entity->id(),
      'entity_uuid' => $entity->uuid(),
    ];
  }

  /**
   * Creates an unsaved dependency; the task save commits it with its owner.
   */
  public function create(TaskInterface $task, string $trigger, array $configuration, string $action, EntityInterface $target): Dependency {
    $matcher = $this->triggers->createInstance($trigger, $configuration);
    $matcher->validateTarget($target);
    if (!in_array($action, ['activate', 'invalidate'], TRUE)) {
      throw new \InvalidArgumentException('Unknown dependency action.');
    }
    if (!$target->access('view')) {
      throw new AccessDeniedHttpException('Access denied to dependency target.');
    }
    if (str_starts_with($trigger, 'entity.state:') && (!$target->get($configuration['field'])->access('view'))) {
      throw new AccessDeniedHttpException('Access denied to the watched field.');
    }
    if ($target->uuid() === $task->uuid()) {
      throw new \InvalidArgumentException('A task cannot depend on itself.');
    }
    return $this->entities->getStorage('task_dependency')->create([
      'owner' => $task->uuid(),
      'trigger' => $trigger,
      'configuration' => $configuration,
      'action' => $action,
      'bindings' => [$this->bind($this->triggers->bindingDefinition($trigger)[0], $target)],
    ]);
  }

  /**
   * Serializes registration with source transitions and validates identity.
   */
  public function presave(Dependency $dependency): void {
    if ($dependency->isNew()) {
      $this->workflow->lockTargets([['entity_type' => 'task_dependency_graph', 'entity_id' => 'graph']]);
    }
    $bindings = $dependency->get('bindings')->getValue();
    $this->workflow->lockTargets($bindings);
    if (count($bindings) !== 1) {
      throw new \InvalidArgumentException('The supplied triggers require exactly one bound entity.');
    }
    $binding = reset($bindings);
    $target = $this->entities->getStorage($binding['entity_type'])->loadUnchanged($binding['entity_id']);
    if (!$target || $target->uuid() !== $binding['entity_uuid']) {
      throw new \InvalidArgumentException('Dependency target no longer exists.');
    }
    $matcher = $this->triggers->createInstance($dependency->get('trigger')->value, $dependency->get('configuration')->first()?->getValue() ?? []);
    $matcher->validateTarget($target);
    if ($binding['context'] !== $this->triggers->bindingDefinition($dependency->get('trigger')->value)[0] || !in_array($dependency->get('action')->value, [
      'activate',
      'invalidate',
    ], TRUE)) {
      throw new \InvalidArgumentException('Invalid dependency binding or action.');
    }
    if ($dependency->isNew()) {
      $dependency->set('met', FALSE)->set('occurrence', NULL)->set('met_at', NULL);
      // Terminal task resolution is monotonic; existing resolution qualifies.
      if ($dependency->get('trigger')->value === 'task.resolved' && $target->get('status')->value === 'resolved') {
        $dependency->set('met', TRUE)->set('met_at', $this->time->getCurrentTime());
      }
      $this->assertAcyclic($dependency);
    }
  }

  /**
   * Rejects task activation cycles across new and legacy dependencies.
   */
  protected function assertAcyclic(Dependency $dependency): void {
    if ($dependency->get('action')->value !== 'activate' || $dependency->get('trigger')->value !== 'task.resolved') {
      return;
    }
    $pending = [$dependency->get('bindings')->first()->entity_id];
    $seen = [];
    while ($id = array_pop($pending)) {
      if (isset($seen[$id])) {
        continue;
      }
      $seen[$id] = TRUE;
      $task = $this->entities->getStorage('task')->loadUnchanged($id);
      if (!$task) {
        continue;
      }
      if ($task->uuid() === $dependency->get('owner')->value) {
        throw new \InvalidArgumentException('Task dependencies must not contain a cycle.');
      }
      foreach ($task->get('dependencies') as $reference) {
        $pending[] = $reference->target_id;
      }
      foreach ($task->get('event_dependencies')->referencedEntities() as $other) {
        if ($other->get('action')->value === 'activate' && $other->get('trigger')->value === 'task.resolved') {
          $pending[] = $other->get('bindings')->first()->entity_id;
        }
      }
    }
  }

  /**
   * Checks the combined legacy/event task graph immediately before storage.
   */
  public function validateTask(TaskInterface $task): void {
    if (isset($task->original) && $task->get('dependencies')->getValue() === $task->original->get('dependencies')->getValue()
      && $task->get('event_dependencies')->getValue() === $task->original->get('event_dependencies')->getValue()) {
      return;
    }
    $this->workflow->lockTargets([['entity_type' => 'task_dependency_graph', 'entity_id' => 'graph']]);
    $targets = static function (TaskInterface $record): array {
      $ids = array_column($record->get('dependencies')->getValue(), 'target_id');
      foreach ($record->get('event_dependencies')->referencedEntities() as $dependency) {
        if ($dependency->get('action')->value === 'activate' && $dependency->get('trigger')->value === 'task.resolved') {
          $ids[] = $dependency->get('bindings')->first()->entity_id;
        }
      }
      return $ids;
    };
    $pending = $targets($task);
    $seen = [];
    while ($id = array_pop($pending)) {
      if (isset($seen[$id])) {
        continue;
      }
      $seen[$id] = TRUE;
      $target = $this->entities->getStorage('task')->loadUnchanged($id);
      if (!$target) {
        continue;
      }
      if ($target->uuid() === $task->uuid()) {
        throw new \InvalidArgumentException('Task dependencies must not contain a cycle.');
      }
      array_push($pending, ...$targets($target));
    }
  }

  /**
   * Records matching occurrences inside the source entity save transaction.
   */
  public function observe(EntityInterface $entity): void {
    if (!$entity instanceof FieldableEntityInterface || !$entity->uuid()
      || ($entity instanceof ContentEntityInterface && !$entity->isDefaultRevision())) {
      return;
    }
    $transaction = $this->database->startTransaction();
    try {
      $this->workflow->lockTargets([$this->bind('entity', $entity)]);
      $storage = $this->entities->getStorage('task_dependency');
      $storage->resetCache();
      $occurrence = $this->uuid->generate();
      foreach ($storage->watching($entity->getEntityTypeId(), (string) $entity->id()) as $dependency) {
        if ($dependency->get('met')->value || $dependency->get('bindings')->first()->entity_uuid !== $entity->uuid()) {
          continue;
        }
        $matcher = $this->triggers->createInstance($dependency->get('trigger')->value, $dependency->get('configuration')->first()?->getValue() ?? []);
        if ($matcher->matches($entity, $entity->original ?? NULL)) {
          $dependency->set('met', TRUE)->set('occurrence', $occurrence)->set('met_at', $this->time->getCurrentTime())->save();
          $this->workflow->record($dependency, 'matched', ['occurrence' => $occurrence]);
          $this->workflow->request($dependency->get('owner')->value);
        }
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Retargets only the dependency UUIDs explicitly selected by the caller.
   *
   * The replacement workflow decides which subscriptions move. No per-record
   * opt-in or status inference is used. An empty selection moves nothing.
   * Terminal owners retain their result. When $unmet_only is TRUE, a receipt
   * recorded since the caller selected dependencies is retained. This check
   * happens under the same locks as event matching.
   */
  public function retarget(EntityInterface $old, EntityInterface $replacement, array $dependency_ids, bool $unmet_only = FALSE): int {
    $transaction = $this->database->startTransaction();
    try {
      $this->workflow->lockTargets([['entity_type' => 'task_dependency_graph', 'entity_id' => 'graph']]);
      $this->workflow->lockTargets([$this->bind('entity', $old), $this->bind('entity', $replacement)]);
      $storage = $this->entities->getStorage('task_dependency');
      $storage->resetCache();
      $this->entities->getStorage('task')->resetCache();
      $count = 0;
      $dependencies = $storage->loadMultiple($dependency_ids);
      if (count($dependencies) !== count(array_unique($dependency_ids))) {
        throw new \InvalidArgumentException('A selected dependency no longer exists.');
      }
      foreach ($dependencies as $dependency) {
        if ($unmet_only && $dependency->get('met')->value) {
          continue;
        }
        $binding = $dependency->get('bindings')->first();
        if ($binding->entity_type !== $old->getEntityTypeId() || (string) $binding->entity_id !== (string) $old->id() || $binding->entity_uuid !== $old->uuid()) {
          throw new \InvalidArgumentException('A selected dependency does not reference the original entity.');
        }
        $owners = $this->entities->getStorage('task')->loadByProperties(['uuid' => $dependency->get('owner')->value]);
        $owner = reset($owners);
        if (!$owner || in_array($owner->get('status')->value, ['resolved', 'closed'], TRUE)) {
          continue;
        }
        if (!$owner->access('update') || !$replacement->access('view')) {
          throw new AccessDeniedHttpException('Access denied to retarget dependency.');
        }
        $binding = $dependency->get('bindings')->first()->getValue();
        if ($old->uuid() === $replacement->uuid()) {
          throw new \InvalidArgumentException('A replacement must be a different entity.');
        }
        $this->workflow->assertReplacement($dependency, $replacement->uuid());
        $next = $this->bind($binding['context'], $replacement);
        $dependency->set('bindings', [$next])->set('met', FALSE)->set('occurrence', NULL)->set('met_at', NULL);
        if ($dependency->get('trigger')->value === 'task.resolved' && $replacement->get('status')->value === 'resolved') {
          $dependency->set('met', TRUE)->set('met_at', $this->time->getCurrentTime());
        }
        $this->assertAcyclic($dependency);
        $dependency->save();
        $this->workflow->record($dependency, 'retargeted', ['from' => $binding, 'to' => $next]);
        $this->workflow->request($owner->uuid());
        $count++;
      }
      return $count;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

}
