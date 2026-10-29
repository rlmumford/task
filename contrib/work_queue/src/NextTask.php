<?php

namespace Drupal\task_work_queue;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\task\TaskReadiness;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Selects the current assignee's next ready task across all three work lanes.
 */
final class NextTask {

  /**
   * Constructs the selector, using the shared readiness and access policies.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly AccountProxyInterface $currentUser,
    private readonly TaskReadiness $readiness,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $config,
  ) {}

  /**
   * Recommends work without saving task, checklist, assignment or queue state.
   *
   * Context is the task the user just worked on, including a completed task.
   * It never supplies an organization or assignee for the query.
   */
  public function select(?int $context_id = NULL): NextTaskResult {
    $user = $this->currentUser->isAuthenticated()
      ? $this->entities->getStorage('user')->loadUnchanged($this->currentUser->id()) : NULL;
    if (!$user || !$user->isActive()) {
      throw new AccessDeniedHttpException();
    }
    $storage = $this->entities->getStorage('task');
    $context = $context_id ? $storage->loadUnchanged($context_id) : NULL;
    if ($context && ((string) $context->get('assignee')->target_id !== (string) $user->id() || !$context->access('view'))) {
      $context = NULL;
    }
    $service_id = NULL;
    if ($context?->hasField('service') && $context->get('service')->access('view')) {
      $service = $context->get('service')->entity;
      $service_id = $service && $service->access('view') ? (string) $service->id() : NULL;
    }
    $settings = $this->config->get('task_work_queue.settings')->getRawData();
    $limit = max(1, (int) $settings['maximum_tasks']);
    $now = $this->time->getCurrentTime();
    $query = $storage->getQuery()->accessCheck(TRUE)
      ->condition('assignee', $user->id())
      ->condition('status', ['active', 'pending', 'waiting'], 'IN');
    $query->condition($query->orConditionGroup()->notExists('start')->condition('start', gmdate('Y-m-d\TH:i:s', $now), '<='));
    if ($context) {
      $query->condition('id', $context->id(), '<>');
    }
    $candidates = array_values($query->sort('id')->range(0, $limit + 1)->execute());
    if (count($candidates) > $limit) {
      return new NextTaskResult(limited: TRUE, context: $context);
    }
    $records = $this->records($candidates);
    $candidates = array_values(array_intersect($candidates, array_keys($records)));
    $frontier = $candidates;
    $visited = $candidates;
    // Follow reverse dependencies, including other staff's work only when the
    // viewer can see it. Never use unrestricted tasks to influence a score.
    while ($frontier) {
      $ids = array_values($storage->getQuery()->accessCheck(TRUE)
        ->condition('status', ['active', 'pending', 'waiting'], 'IN')
        ->condition('dependencies.target_id', $frontier, 'IN')
        ->condition('id', $visited, 'NOT IN')
        ->sort('id')->range(0, $limit - count($visited) + 1)->execute());
      if (count($visited) + count($ids) > $limit) {
        return new NextTaskResult(limited: TRUE, context: $context);
      }
      $visited = array_merge($visited, $ids);
      $additional = $this->records($ids);
      $records += $additional;
      $frontier = array_keys($additional);
    }
    $urgency = new Urgency($settings, $now);
    $scores = $urgency->scores($records, $candidates);
    $lanes = [];
    foreach ($candidates as $id) {
      $lanes[$id] = $urgency->isInterrupter($records[$id]) ? 0
        : ($service_id !== NULL && $records[$id]['service'] === $service_id ? 1 : 2);
    }
    usort($candidates, static function ($a, $b) use ($records, $scores, $lanes): int {
      // Interruptions are oldest-start first; Flow and Plan use urgency.
      return ($lanes[$a] <=> $lanes[$b])
        ?: ($lanes[$a] === 0 ? (($records[$a]['start'] ?? 0) <=> ($records[$b]['start'] ?? 0)) : ($scores[$b] <=> $scores[$a]))
        ?: ($a <=> $b);
    });
    // Fresh access/readiness remain authoritative even if stored task status
    // or a recommendation became stale while ranking was in progress.
    foreach ($candidates as $id) {
      $task = $storage->loadUnchanged($id);
      if ($task && (string) $task->get('assignee')->target_id === (string) $user->id()
        && $task->access('view') && $task->access('update') && $this->readiness->evaluate($task)->state === 'active') {
        return new NextTaskResult($task, ['interrupter', 'flow', 'plan'][$lanes[$id]], context: $context);
      }
    }
    return new NextTaskResult(context: $context);
  }

  /**
   * Extracts small scoring records, loading entities in bounded batches.
   */
  private function records(array $ids): array {
    $storage = $this->entities->getStorage('task');
    $records = [];
    foreach (array_chunk($ids, 100) as $batch) {
      $storage->resetCache($batch);
      foreach ($storage->loadMultiple($batch) as $task) {
        if (!$task->access('view')) {
          continue;
        }
        $priority = $task->get('work_priority')->value;
        $record = [
          'status' => $task->get('status')->value,
          'priority' => in_array($priority, ['low', 'medium', 'high'], TRUE) ? $priority : 'medium',
          'estimate' => max(0, (int) $task->get('work_estimate')->value),
          'interrupter' => (bool) $task->get('work_interrupter')->value,
          'service' => $task->hasField('service') && !$task->get('service')->isEmpty() ? (string) $task->get('service')->target_id : NULL,
          'dependencies' => array_column($task->get('dependencies')->getValue(), 'target_id'),
        ];
        foreach (['start', 'due', 'deadline'] as $field) {
          $value = $task->get($field)->value;
          $record[$field] = $value ? (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp() : NULL;
        }
        $records[$task->id()] = $record;
      }
      $storage->resetCache($batch);
    }
    return $records;
  }

}
