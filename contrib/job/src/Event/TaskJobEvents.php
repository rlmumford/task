<?php

namespace Drupal\task_job\Event;

/**
 * Events dispatched by TaskJob module.
 *
 * @package Drupal\task_job\Event
 */
final class TaskJobEvents {

  /**
   * Select job detect environment.
   *
   * This event is fired on the select job page to make sure the right jobs
   * are loaded based on the environment.
   */
  const SELECT_JOB_DETECT_ENVIRONMENT = 'task_job.select_job_detect_environment';

  /**
   * Handle trigger detect environment.
   *
   * This event is fired immediately before a trigger is handled to detect what
   * environment the trigger should be executed in.
   */
  const HANDLE_TRIGGER_DETECT_ENVIRONMENT = 'task_job.handle_trigger_detect_environment';

  /**
   * Collect trigger access information.
   *
   * This event is fired when the access to a particular trigger is tested.
   */
  const TRIGGER_ACCESS = 'task_job.trigger_access';

}
