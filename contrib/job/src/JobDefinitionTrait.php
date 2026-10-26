<?php

namespace Drupal\task_job;

use Drupal\Component\Plugin\LazyPluginCollection;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface;
use Drupal\task_job\Plugin\JobTrigger\LazyJobTriggerCollection;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\ContextDefinitionInterface;

/**
 * Checklist, trigger, resource and context behavior shared by job stores.
 */
trait JobDefinitionTrait {

  /**
   * Get the default checklist items for this job.
   *
   * @return array
   *   An array of checklist item configuration keyed by the name.
   *   Each item should have atleast the following keys:
   *     - name - The name of the checklist itm
   *     - label - The label of the checklist item
   *     - handler - The handler plugin used for the checklist item.
   *     - handler_configuration - The configuration to be passed to the plugin.
   */
  public function getChecklistItems(?string $template = NULL): array {
    if ($template === NULL) {
      return $this->getDefinitionValue('default_checklist') ?: [];
    }
    $templates = $this->getDefinitionValue('checklist_templates') ?: [];
    if (!isset($templates[$template])) {
      throw new \InvalidArgumentException(sprintf('Checklist template "%s" does not exist.', $template));
    }
    return $templates[$template]['items'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setChecklistItems(array $items, ?string $template = NULL): void {
    if ($template === NULL) {
      $this->setDefinitionValue('default_checklist', $items);
      return;
    }
    $this->getChecklistItems($template);
    $templates = $this->getDefinitionValue('checklist_templates');
    $templates[$template]['items'] = $items;
    $this->setDefinitionValue('checklist_templates', $templates);
  }

  /**
   * {@inheritdoc}
   */
  public function getExpandedChecklistItems(): array {
    $items = $this->getChecklistItems();
    $seen = [];
    foreach ($this->getDefinitionValue('checklist_includes') ?: [] as $template) {
      if (isset($seen[$template])) {
        throw new \InvalidArgumentException(sprintf('Checklist template "%s" is included more than once.', $template));
      }
      $seen[$template] = TRUE;
      foreach ($this->getChecklistItems($template) as $name => $item) {
        if (isset($items[$name])) {
          throw new \InvalidArgumentException(sprintf('Checklist item "%s" occurs more than once after including template "%s".', $name, $template));
        }
        $items[$name] = $item;
      }
    }
    return JobChecklistExpansion::expand($items, $this->getDefinitionValue('checklist_templates') ?: []);
  }

  /**
   * {@inheritdoc}
   */
  public function getResourcesConfiguration(): array {
    return $this->getDefinitionValue('resources') ?: [];
  }

  /**
   * {@inheritdoc}
   */
  public function getResourcesCollection(): LazyPluginCollection {
    if (!$this->resourcesCollection) {
      $this->resourcesCollection = new DefaultLazyPluginCollection(
        \Drupal::service('plugin.manager.block'),
        $this->getResourcesConfiguration()
      );
    }

    return $this->resourcesCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function getTriggersConfiguration(): array {
    return $this->getDefinitionValue('triggers') ?: [];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultTriggersConfiguration(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getTriggerCollection(): LazyPluginCollection {
    if (!$this->triggerCollection) {
      $this->triggerCollection = new LazyJobTriggerCollection(
        $this,
        \Drupal::service('plugin.manager.task_job.trigger'),
        $this->getTriggersConfiguration() ?: $this->defaultTriggersConfiguration()
      );
    }

    return $this->triggerCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function getTrigger(string $key): ?JobTriggerInterface {
    return $this->getTriggerCollection()->get($key);
  }

  /**
   * {@inheritdoc}
   */
  public function hasTrigger(string $key): bool {
    $triggers = $this->getTriggersConfiguration();
    return isset($triggers[$key]);
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinitions() {
    $definitions = [];

    foreach ($this->getDefinitionValue('context') ?: [] as $key => $context) {
      $definitions[$key] = ContextDefinition::create($context['type'])
        ->setLabel($context['label'])
        ->setRequired($context['required'] ?? TRUE)
        ->setMultiple($context['multiple'] ?? FALSE)
        ->setDescription($context['description'] ?? '');
    }

    return $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinition(string $key) {
    return $this->getContextDefinitions()[$key] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function addContextDefinition(string $key, ContextDefinitionInterface $context_definition) {
    $contexts = $this->getDefinitionValue('context') ?: [];
    $contexts[$key] = [
      'type' => $context_definition->getDataType(),
      'label' => (string) $context_definition->getLabel(),
      'required' => $context_definition->isRequired(),
      'multiple' => $context_definition->isMultiple(),
      'description' => (string) $context_definition->getDescription(),
    ];
    $this->setDefinitionValue('context', $contexts);
  }

  /**
   * {@inheritdoc}
   */
  public function removeContextDefinition(string $key) {
    $contexts = $this->getDefinitionValue('context') ?: [];
    unset($contexts[$key]);
    $this->setDefinitionValue('context', $contexts);
  }

}
