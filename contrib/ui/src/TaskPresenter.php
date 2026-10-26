<?php

namespace Drupal\task_ui;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\task\TaskInterface;
use Drupal\task\TaskReadiness;

/**
 * Shared task presentation with live readiness and access-checked references.
 */
final class TaskPresenter {

  use StringTranslationTrait;

  /**
   * Constructs the work list presenter.
   */
  public function __construct(
    private readonly TaskReadiness $readiness,
    private readonly DateFormatterInterface $dates,
    private readonly TimeInterface $time,
    private readonly ModuleHandlerInterface $modules,
    private readonly EntityTypeManagerInterface $entities,
  ) {}

  /**
   * Batch-loads references for the bounded Views result page.
   */
  public function preloadReferences(array $tasks, array $fields): void {
    foreach ($fields as $field) {
      $ids = [];
      $type = NULL;
      foreach ($tasks as $task) {
        if ($task->hasField($field) && ($id = $task->get($field)->target_id)) {
          $type = $task->get($field)->getFieldDefinition()->getSetting('target_type');
          $ids[$id] = $id;
        }
      }
      if ($ids) {
        $this->entities->getStorage($type)->loadMultiple($ids);
      }
    }
  }

  /**
   * Shows only references independently visible to this viewer.
   */
  public function reference(?EntityInterface $entity): mixed {
    return $entity && $entity->access('view') ? $entity->toLink() : $this->t('—');
  }

  /**
   * Shows staff names only while they belong to the task's organization.
   */
  public function assignee(TaskInterface $task): mixed {
    if ($task->get('assignee')->isEmpty()) {
      return $this->t('Unassigned');
    }
    $user = $task->get('assignee')->entity;
    $label = $user && $user->access('view') ? $user->getDisplayName() : $this->t('Assigned');
    $this->modules->alter('task_ui_assignee', $label, $task);
    return $label;
  }

  /**
   * Uses shared readiness; diagnostics containing foreign IDs stay private.
   */
  public function state(TaskInterface $task): mixed {
    return $this->stateLabel($this->readiness->evaluate($task)->state);
  }

  /**
   * Human-readable readiness labels without exposing dependency details.
   */
  private function stateLabel(string $state): mixed {
    return match ($state) {
      'active' => $this->t('Ready'),
      'pending' => $this->t('Scheduled'),
      'waiting' => $this->t('Blocked — waiting for required work'),
      'invalid' => $this->t('Needs manager attention'),
      'resolved' => $this->t('Resolved'),
      'closed' => $this->t('Closed'),
    };
  }

  /**
   * Formats separate due/deadline fields using the viewer's timezone.
   */
  public function date(TaskInterface $task, string $field, bool $overdue = FALSE): mixed {
    $value = $task->get($field)->value;
    if (!$value) {
      return $this->t('—');
    }
    $timestamp = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
    $formatted = $this->dates->format($timestamp, 'short');
    return $overdue && $timestamp < $this->time->getCurrentTime()
      && !in_array($task->get('status')->value, ['resolved', 'closed'], TRUE)
      ? $this->t('@date — overdue', ['@date' => $formatted]) : $formatted;
  }

  /**
   * Links to a protected form; never changes assignment through GET.
   */
  public function actions(TaskInterface $task): array {
    $actions = [];
    $this->modules->alter('task_ui_task_actions', $actions, $task);
    return $actions;
  }

  /**
   * Applications opt reference fields into the list and task summary.
   */
  public function contextFields(): array {
    $fields = [];
    $this->modules->alter('task_ui_context_fields', $fields);
    return $fields;
  }

}
