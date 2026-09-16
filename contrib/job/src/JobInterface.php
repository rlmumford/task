<?php

namespace Drupal\task_job;

use Drupal\Component\Plugin\LazyPluginCollection;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface;
use Drupal\typed_data\Context\ContextDefinition;

/**
 * Interface for Jobs.
 */
interface JobInterface extends ConfigEntityInterface {

  /**
   * Get the default checklist items for this job.
   *
   * @return array
   *   An array of checklist item configuration keyed by the name.
   *   Each item should have atleast the following keys:
   *     - label - The label of the checklist item
   *     - handler - The handler plugin used for the checklist item.
   *     - handler_configuration - The configuration to be passed to the plugin.
   */
  public function getChecklistItems() : array;

  /**
   * Get the resources to be displayed on task of this job.
   *
   * @return array
   *   An array of resources configuration.
   */
  public function getResourcesConfiguration() : array;

  /**
   * Get the resources collection.
   *
   * @return \Drupal\Component\Plugin\LazyPluginCollection
   *   The resources collection.
   */
  public function getResourcesCollection(): LazyPluginCollection;

  /**
   * Get the triggers associated with this job.
   *
   * @return array
   *   An array of triggers configuration.
   */
  public function getTriggersConfiguration() : array;

  /**
   * Get the trigger collection.
   *
   * @return \Drupal\Component\Plugin\LazyPluginCollection
   *   The trigger collection.
   */
  public function getTriggerCollection(): LazyPluginCollection;

  /**
   * Get a specific trigger.
   *
   * @param string $key
   *   The trigger key.
   *
   * @return \Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface|null
   *   The job trigger plugin if it exists.
   */
  public function getTrigger(string $key): ?JobTriggerInterface;

  /**
   * Check whether we have a trigger.
   *
   * @param string $key
   *   The trigger key.
   *
   * @return bool
   *   True if the trigger exists, FALSE otherwise.
   */
  public function hasTrigger(string $key) : bool;

  /**
   * Get the context definitions for this job.
   *
   * @return \Drupal\Core\Plugin\Context\ContextDefinitionInterface[]
   *   The context definitions associated with this job.
   */
  public function getContextDefinitions();

  /**
   * Get the context definition.
   *
   * @param string $key
   *   The context key to get.
   *
   * @return \Drupal\typed_data\Context\ContextDefinition|null
   *   Get the context definition.
   *
   * @throws \Drupal\Component\Plugin\Exception\ContextException
   */
  public function getContextDefinition(string $key);

  /**
   * Add a context definition.
   *
   * @param string $key
   *   The name of the context.
   * @param \Drupal\typed_data\Context\ContextDefinition $context_definition
   *   The definition of the context.
   */
  public function addContextDefinition(string $key, ContextDefinition $context_definition);

  /**
   * Remove a context definition.
   *
   * @param string $key
   *   The key of the context to remove.
   */
  public function removeContextDefinition(string $key);

}
