<?php

namespace Drupal\task_job\Event;

use Drupal\exec_environment\Event\EnvironmentDetectionEvent;

/**
 * Event to allow setting of environment before handling a trigger.
 *
 * @package Drupal\task_job\Event
 */
class HandleTriggerEnvironmentDetectionEvent extends EnvironmentDetectionEvent {

  /**
   * The event plugin id.
   *
   * @var string
   */
  protected $triggerId;

  /**
   * The context values for the trigger.
   *
   * @var array
   */
  protected $contextValues;

  /**
   * HandleTriggerEnvironmentDetectionEvent constructor.
   *
   * @param string $trigger_id
   *   The event plugin id.
   * @param array $context_values
   *   The context values keyed by context name.
   */
  public function __construct(string $trigger_id, array $context_values) {
    $this->triggerId = $trigger_id;
    $this->contextValues = $context_values;
  }

  /**
   * Get the context values.
   *
   * @return array
   *   The context values keyed by name.
   */
  public function getContextValues() : array {
    return $this->contextValues;
  }

  /**
   * Get a particular context value.
   *
   * @param string $name
   *   The context name.
   *
   * @return mixed|null
   *   The context value.
   */
  public function getContextValue(string $name) {
    return $this->contextValues[$name] ?? NULL;
  }

  /**
   * Get the trigger id.
   *
   * @return string
   *   The trigger id.
   */
  public function getTriggerId() : string {
    return $this->triggerId;
  }

}
