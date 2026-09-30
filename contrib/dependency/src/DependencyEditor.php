<?php

namespace Drupal\task_dependency;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\task\TaskInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Shares working-copy editing between Form API, Flexiform and its API.
 */
class DependencyEditor {

  /**
   * Constructs the editor.
   */
  public function __construct(protected DependencyManager $manager, protected EntityTypeManagerInterface $entities, protected TriggerManager $triggers) {}

  /**
   * Checks task and dependency-field access before disclosure or mutation.
   */
  public function authorize(TaskInterface $task): void {
    $allowed = $task->isNew() ? $this->entities->getAccessControlHandler('task')->createAccess() : $task->access('view') && $task->access('update');
    if (!$allowed || !$task->get('event_dependencies')->access('view') || !$task->get('event_dependencies')->access('edit')) {
      throw new AccessDeniedHttpException('Access denied to task dependencies.');
    }
  }

  /**
   * Returns editable definitions; satisfaction is not user input.
   */
  public function values(TaskInterface $task): array {
    $this->authorize($task);
    $rows = [];
    foreach ($task->get('event_dependencies')->referencedEntities() as $dependency) {
      $binding = $dependency->get('bindings')->first();
      $target = $this->entities->getStorage($binding->entity_type)->load($binding->entity_id);
      if (!$target || $target->uuid() !== $binding->entity_uuid || !$target->access('view')) {
        throw new AccessDeniedHttpException('A dependency target is unavailable or inaccessible.');
      }
      $config = $dependency->get('configuration')->first()?->getValue() ?? [];
      if (str_starts_with($dependency->get('trigger')->value, 'entity.state:') && $target->hasField($config['field'] ?? '') && !$target->get($config['field'])->access('view')) {
        throw new AccessDeniedHttpException('Access denied to the watched field.');
      }
      $rows[] = [
        'id' => $dependency->uuid(),
        'trigger' => $dependency->get('trigger')->value,
        'action' => $dependency->get('action')->value,
        'entity_id' => (string) $binding->entity_id,
        'field' => $config['field'] ?? 'status',
        'property' => $config['property'] ?? 'value',
        'value' => (string) ($config['value'] ?? ''),
      ];
    }
    return $rows;
  }

  /**
   * Builds a replacement working collection without saving any records.
   */
  public function prepare(TaskInterface $task, array $rows): array {
    $existing = array_column($this->values($task), NULL, 'id');
    $entities = [];
    foreach ($task->get('event_dependencies')->referencedEntities() as $dependency) {
      $entities[$dependency->uuid()] = $dependency;
    }
    $result = [];
    $seen = [];
    foreach ($rows as $row) {
      if (!is_array($row) || array_diff(array_keys($row), [
        'id',
        'trigger',
        'action',
        'entity_id',
        'field',
        'property',
        'value',
      ])) {
        throw new \InvalidArgumentException('Unexpected dependency input.');
      }
      $row += [
        'id' => '',
        'field' => 'status',
        'property' => 'value',
        'value' => '',
      ];
      foreach ($row as $key => $value) {
        if (!is_string($value)) {
          throw new \InvalidArgumentException('Invalid dependency value type.');
        }
      }
      $id = $row['id'];
      if ($id && (!isset($existing[$id]) || isset($seen[$id]))) {
        throw new \InvalidArgumentException('Unknown or duplicate dependency ID.');
      }
      $seen[$id] = TRUE;
      $previous = $existing[$id] ?? [];
      ksort($previous);
      $candidate = $row;
      ksort($candidate);
      if ($id && $previous === $candidate) {
        $result[] = ['entity' => $entities[$id]];
        continue;
      }
      foreach (['trigger', 'action', 'entity_id'] as $key) {
        if (!isset($row[$key]) || !is_string($row[$key]) || $row[$key] === '') {
          throw new \InvalidArgumentException('A dependency requires an event, action and saved target.');
        }
      }
      [, $definition] = $this->triggers->bindingDefinition($row['trigger']);
      $type = substr($definition->getDataType(), 7);
      $target = $this->entities->getStorage($type)->load($row['entity_id']);
      if (!$target) {
        throw new \InvalidArgumentException('Select an existing dependency target.');
      }
      $config = array_intersect_key($row, array_flip(['field', 'property', 'value']));
      $dependency = $this->manager->create($task, $row['trigger'], $config, $row['action'], $target);
      $result[] = ['entity' => $dependency];
    }
    return $result;
  }

}
