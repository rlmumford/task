<?php

namespace Drupal\task\Event;

/**
 * Define task event names.
 */
final class TaskEvents {

  /**
   * Name of the event fired when assigning a task.
   *
   * @Event
   */
  const SELECT_ASSIGNEE = 'task.select_assignee';

  /**
   * The collect resources event is fired to determine what resources to render.
   *
   * @Event
   */
  const COLLECT_RESOURCES = 'task.collect_resources';

  /**
   * This event is fired when determining resources to expose contexts.
   *
   * @Event
   */
  const COLLECT_RESOURCES_CONTEXTS = 'task.collect_resources_contexts';

}
