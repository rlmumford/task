<?php

namespace Drupal\task;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Interface for tasks.
 */
interface TaskInterface extends FieldableEntityInterface {

  /**
   * Status Pending.
   *
   * Tasks are pending when they have not yet reached their start date.
   */
  const STATUS_PENDING = 'pending';

  /**
   * Status Active.
   *
   * Tasks are active when they are currently available to be worked on.
   */
  const STATUS_ACTIVE = 'active';

  /**
   * Status Waiting.
   *
   * Tasks are waiting when they have unresolved dependencies.
   */
  const STATUS_WAITING = 'waiting';

  /**
   * Status Resolved.
   *
   * Tasks are resolved when the work required has been completed.
   */
  const STATUS_RESOLVED = 'resolved';

  /**
   * Status Closed.
   *
   * Tasks are closed once they never have to be looked at again. Normally this
   * is after a manager has signed off the work.
   */
  const STATUS_CLOSED = 'closed';

  /**
   * Resolution Complete.
   *
   * The task has been completed.
   */
  const RESOLUTION_COMPLETE = 'complete';

  /**
   * Resolution incomplete.
   *
   * The task is 'resolved' but the work has not been completed.
   */
  const RESOLUTION_INCOMPLETE = 'incomplete';

  /**
   * Resolution invalid.
   *
   * The task is resolved because for some reason the work is no longer
   * required.
   */
  const RESOLUTION_INVALID = 'invalid';

  /**
   * Resolution duplicate.
   *
   * The task is resolved because another task exists covering the same work.
   */
  const RESOLUTION_DUPLICATE = 'duplicate';

  /**
   * Get the status options for the task.
   *
   * @return array
   *   List of options.
   */
  public static function statusOptionsList();

  /**
   * Get the resolution options for tasks.
   *
   * @return array
   *   List of options.
   */
  public static function resolutionOptionsList();

}
