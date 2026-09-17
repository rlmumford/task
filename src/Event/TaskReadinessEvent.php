<?php

namespace Drupal\task\Event;

use Drupal\task\TaskInterface;

/**
 * Collects readiness contributions without replacing or stopping other gates.
 *
 * Dispatched using this class name. Subscribers must not mutate the task or
 * perform side effects. This deliberately is not a stoppable event: every
 * subscriber contributes before the evaluator applies readiness precedence.
 */
final class TaskReadinessEvent {

  /**
   * The contributed reasons.
   *
   * @var array
   */
  private array $reasons = [];

  /**
   * Constructs the event for a task, which may be unsaved.
   */
  public function __construct(private readonly TaskInterface $task) {}

  /**
   * Gets the task being evaluated; subscribers must treat it as read-only.
   */
  public function getTask(): TaskInterface {
    return $this->task;
  }

  /**
   * Adds a readiness decision with a code and optional diagnostic values.
   *
   * States are active, pending, waiting, or invalid. Codes should identify the
   * contributing module and reason. Check access before displaying diagnostics.
   * Invalid recommends resolution; this event does not save anything.
   */
  public function addReason(string $state, string $code, array $details = []): void {
    if (!in_array($state, ['active', 'pending', 'waiting', 'invalid'], TRUE) || $code === '') {
      throw new \UnexpectedValueException('A readiness reason requires a valid state and a non-empty code.');
    }
    $this->reasons[] = ['state' => $state, 'code' => $code] + $details;
  }

  /**
   * Gets the collected contributions, without exposing a mutable collection.
   */
  public function getReasons(): array {
    return $this->reasons;
  }

}
