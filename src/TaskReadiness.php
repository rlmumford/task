<?php

namespace Drupal\task;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\task\Event\TaskReadinessEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;

/**
 * Evaluates task readiness without changing task or service entities.
 */
class TaskReadiness {

  /**
   * Constructs the evaluator.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Evaluates supplied task values against current default referenced entities.
   *
   * The caller must supply the current task and enforce access separately.
   * Results are not cached: readiness can change with time or another entity.
   * Reasons may contain inaccessible entity IDs; filter them before display.
   */
  public function evaluate(TaskInterface $task): TaskReadinessResult {
    $reasons = [];
    $pending = FALSE;
    $waiting = FALSE;
    $invalid = FALSE;
    $status = $task->get('status')->value;
    $now = gmdate(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, $this->time->getCurrentTime());
    if ($task->get('start')->value && $task->get('start')->value > $now) {
      $pending = TRUE;
      $reasons[] = ['state' => 'pending', 'code' => 'future_start'];
    }
    if (!in_array($status, [NULL, '', 'active', 'pending', 'waiting', 'resolved', 'closed'], TRUE)) {
      $waiting = TRUE;
      $reasons[] = ['state' => 'waiting', 'code' => 'invalid_task_status', 'status' => $status];
    }

    $ids = [];
    foreach ($task->get('dependencies') as $item) {
      if ($item->target_id !== NULL) {
        $ids[] = $item->target_id;
      }
    }
    $dependencies = [];
    if ($ids) {
      $storage = $this->entityTypeManager->getStorage('task');
      $storage->resetCache($ids);
      $dependencies = $storage->loadMultiple($ids);
    }
    foreach ($task->get('dependencies') as $item) {
      $dependency = $dependencies[$item->target_id] ?? NULL;
      if (!$dependency || $dependency->get('status')->value !== TaskInterface::STATUS_RESOLVED) {
        $waiting = TRUE;
        $reasons[] = [
          'state' => 'waiting',
          'code' => $dependency ? 'dependency_unresolved' : 'dependency_missing',
          'target_id' => $item->target_id,
        ];
      }
    }

    $event = new TaskReadinessEvent($task);
    $this->eventDispatcher->dispatch($event);
    foreach ($event->getReasons() as $reason) {
      $invalid = $invalid || $reason['state'] === 'invalid';
      $pending = $pending || $reason['state'] === 'pending';
      $waiting = $waiting || $reason['state'] === 'waiting';
      $reasons[] = $reason;
    }

    // Preserve terminal outcomes; invalidation wins over ordinary readiness.
    $state = match (TRUE) {
      in_array($status, ['resolved', 'closed'], TRUE) => $status,
      $invalid => 'invalid',
      $pending => 'pending',
      $waiting => 'waiting',
      default => 'active',
    };
    return new TaskReadinessResult($state, $reasons);
  }

}
