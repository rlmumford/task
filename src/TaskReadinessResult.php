<?php

namespace Drupal\task;

/**
 * A readiness decision at one point in time.
 */
class TaskReadinessResult {

  /**
   * Constructs the result with a primary state and all gating reasons.
   *
   * @param string $state
   *   Active, pending, waiting, invalid, resolved, or closed.
   * @param array $reasons
   *   Reasons containing a readiness state, code, and optional diagnostics.
   */
  public function __construct(
    public readonly string $state,
    public readonly array $reasons,
  ) {}

}
