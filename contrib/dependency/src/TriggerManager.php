<?php

namespace Drupal\task_dependency;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\task_dependency\Annotation\DependencyTrigger;

/**
 * Discovers reusable event matchers without depending on task-job creation.
 */
class TriggerManager extends DefaultPluginManager {

  /**
   * Constructs the trigger manager.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache, ModuleHandlerInterface $modules) {
    parent::__construct('Plugin/DependencyTrigger', $namespaces, $modules, TriggerInterface::class, DependencyTrigger::class);
    $this->alterInfo('task_dependency_trigger_info');
    $this->setCacheBackend($cache, 'task_dependency_trigger_info');
  }

  /**
   * Gets the sole bindable entity context supported by this first event source.
   */
  public function bindingDefinition(string $id): array {
    $contexts = $this->getDefinition($id)['context_definitions'] ?? [];
    if (count($contexts) !== 1) {
      throw new \InvalidArgumentException('This source requires one declared entity context.');
    }
    $definition = reset($contexts);
    if (!str_starts_with($definition->getDataType(), 'entity:')) {
      throw new \InvalidArgumentException('This source requires an entity context.');
    }
    return [key($contexts), $definition];
  }

  /**
   * Lists event choices from plugin discovery, including contributed events.
   */
  public function options(): array {
    $options = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      $options[$id] = $definition['label'];
    }
    return $options;
  }

}
