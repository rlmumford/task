<?php

namespace Drupal\task_work_queue;

use Drupal\task\TaskInterface;

/**
 * A read-only recommendation, or an explicit incomplete/empty search result.
 */
final class NextTaskResult {

  /**
   * Constructs the selection result; a recommendation does not reserve work.
   */
  public function __construct(
    public readonly ?TaskInterface $task = NULL,
    public readonly ?string $queue = NULL,
    public readonly bool $limited = FALSE,
    public readonly ?TaskInterface $context = NULL,
  ) {}

}
