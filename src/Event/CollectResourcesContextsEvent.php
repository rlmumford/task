<?php

namespace Drupal\task\Event;

use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\task\TaskInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event to collect additional contexts for task resources.
 *
 * @package Drupal\task\Event
 */
class CollectResourcesContextsEvent extends Event {

  /**
   * The task.
   *
   * @var \Drupal\task\TaskInterface
   */
  protected $task;

  /**
   * The available contexts.
   *
   * @var \Drupal\Core\Plugin\Context\ContextInterface[]
   */
  protected $contexts = [];

  /**
   * CollectResourcesContextsEvent constructor.
   *
   * @param \Drupal\task\TaskInterface $task
   *   The task.
   */
  public function __construct(TaskInterface $task) {
    $this->task = $task;
  }

  /**
   * Get the task.
   *
   * @return \Drupal\task\TaskInterface
   *   The task.
   */
  public function getTask(): TaskInterface {
    return $this->task;
  }

  /**
   * Get the contexts.
   *
   * @return \Drupal\Component\Plugin\Context\ContextInterface[]
   *   The contexts.
   */
  public function getContexts() : array {
    return $this->contexts;
  }

  /**
   * Get a context with a particular key.
   *
   * @param string $key
   *   The key.
   *
   * @return \Drupal\Core\Plugin\Context\ContextInterface|null
   *   The context or null if it does not exist.
   */
  public function getContext(string $key) : ?ContextInterface {
    return $this->contexts[$key] ?? NULL;
  }

  /**
   * Add a context to the list.
   *
   * @param string $key
   *   The key.
   * @param \Drupal\Core\Plugin\Context\ContextInterface $context
   *   The context to add.
   *
   * @return $this
   */
  public function addContext(string $key, ContextInterface $context) {
    $this->contexts[$key] = $context;
    return $this;
  }

  /**
   * Remove a context from the the list.
   *
   * @param string $key
   *   The key.
   *
   * @return $this
   */
  public function removeContext($key) {
    unset($this->contexts[$key]);
    return $this;
  }

}
