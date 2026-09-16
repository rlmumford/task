<?php

namespace Drupal\task\Event;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\task\TaskInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event to collect resources.
 *
 * @package Drupal\task\Event
 */
class CollectResourcesEvent extends Event {

  /**
   * The contexts available.
   *
   * @var \Drupal\Core\Plugin\Context\ContextInterface[]
   */
  protected $contexts;

  /**
   * The task.
   *
   * @var \Drupal\task\TaskInterface
   */
  protected $task;

  /**
   * The blocks.
   *
   * @var \Drupal\Core\Plugin\DefaultLazyPluginCollection
   */
  protected $resources;

  /**
   * CollectResourcesEvent constructor.
   *
   * @param \Drupal\Core\Block\BlockManagerInterface $block_manager
   *   The block plugin manager.
   * @param \Drupal\task\TaskInterface $task
   *   The task resources are being collected for.
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The contexts available.
   */
  public function __construct(BlockManagerInterface $block_manager, TaskInterface $task, array $contexts = []) {
    $this->task = $task;
    $this->contexts = $contexts + [
      'task' => new EntityContext(
        new EntityContextDefinition('task', 'The Task'),
        $this->task
      ),
    ];
    // @todo replace this with a new class so that blocks get sorted by weight.
    $this->resources = new DefaultLazyPluginCollection($block_manager);
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
   * @return \Drupal\Core\Plugin\Context\ContextInterface[]
   *   The contexts keyed by context name.
   */
  public function getContexts(): array {
    return $this->contexts;
  }

  /**
   * Get the resources.
   *
   * @return \Drupal\Core\Plugin\DefaultLazyPluginCollection
   *   The collection of resource blocks.
   */
  public function getResources() : DefaultLazyPluginCollection {
    return $this->resources;
  }

  /**
   * Add a resource to the list.
   *
   * @param string $key
   *   The resource key.
   * @param \Drupal\Core\Block\BlockPluginInterface|string $plugin_or_id
   *   The block plugin or plugin id.
   * @param array $configuration
   *   The block configuration, ignored if a instantiated plugin is provided.
   *
   * @return $this
   */
  public function addResource(string $key, $plugin_or_id, array $configuration = []) {
    if ($plugin_or_id instanceof BlockPluginInterface) {
      $this->resources->set($key, $plugin_or_id);
    }
    else {
      $configuration['id'] = $plugin_or_id;
      $this->resources->addInstanceId($key, $configuration);
    }

    return $this;
  }

}
