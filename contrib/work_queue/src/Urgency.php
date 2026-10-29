<?php

namespace Drupal\task_work_queue;

/**
 * Computes the platform's time/priority urgency without persisting task state.
 */
final class Urgency {

  /**
   * Constructs the calculator with one clock snapshot and configured weights.
   */
  public function __construct(private readonly array $settings, private readonly int $now) {}

  /**
   * Calculates intrinsic urgency from normalized task inputs.
   */
  public function base(array $task): float {
    $parts = [0.0];
    foreach (['start', 'due'] as $date) {
      if ($task[$date] !== NULL) {
        $days = ($this->now - $task[$date]) / 86400;
        $parts[] = min($this->settings[$date . '_maximum'], $this->settings[$date . '_base'] + $days * $this->settings[$date . '_accumulation']);
      }
    }
    $base = max($parts) * $this->settings[$task['priority'] . '_multiplier'];
    if ($task['status'] === 'waiting') {
      $base *= $this->settings['waiting_multiplier'];
    }
    if ($task['deadline'] !== NULL) {
      $base *= $this->settings['deadline_multiplier'];
    }
    return $base;
  }

  /**
   * Adds blocked downstream work once per candidate, tolerating graph cycles.
   *
   * Records must already be scoped to the viewer. A diamond counts its shared
   * descendant once; a cycle cannot recurse forever or multiply its own score.
   */
  public function scores(array $records, array $candidates): array {
    $base = $dependents = [];
    foreach ($records as $id => $record) {
      $base[$id] = $this->base($record);
      foreach ($record['dependencies'] as $dependency) {
        $dependents[$dependency][] = $id;
      }
    }
    $scores = [];
    foreach ($candidates as $id) {
      $pending = [$id];
      $seen = [];
      $score = 0.0;
      while ($pending) {
        $next = array_pop($pending);
        if (isset($seen[$next])) {
          continue;
        }
        $seen[$next] = TRUE;
        $score += $base[$next];
        foreach ($dependents[$next] ?? [] as $dependent) {
          $pending[] = $dependent;
        }
      }
      $scores[$id] = (int) round($score);
    }
    return $scores;
  }

  /**
   * Explicit interruptions and deadlines inside estimate plus warning time.
   */
  public function isInterrupter(array $task): bool {
    return $task['interrupter'] || ($task['deadline'] !== NULL &&
      $task['deadline'] - $task['estimate'] <= $this->now + $this->settings['interrupt_grace']);
  }

}
