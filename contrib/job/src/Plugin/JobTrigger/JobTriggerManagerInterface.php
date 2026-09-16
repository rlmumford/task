<?php

namespace Drupal\task_job\Plugin\JobTrigger;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\task_job\JobInterface;

/**
 * Interface for Job Trigger Manager services.
 */
interface JobTriggerManagerInterface extends PluginManagerInterface {

  /**
   * Update the job trigger index.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job to update the index for.
   */
  public function updateTriggerIndex(JobInterface $job);

  /**
   * Get the trigger plugin instances for this plugin id.
   *
   * @param string|null $plugin_id
   *   The trigger plugin id.
   * @param string|null $base_plugin_id
   *   The base plugin id.
   *
   * @return \Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface[]
   *   A list of triggers.
   */
  public function getTriggers(?string $plugin_id = NULL, ?string $base_plugin_id = NULL) : array;

  /**
   * Get all of the trigger ids that are in use.
   *
   * @param string|null $base_plugin_id
   *   Optional filter by base plugin id.
   *
   * @return string[]
   *   The trigger plugin ids that are currently in use.
   */
  public function getInUseTriggerIds(?string $base_plugin_id = NULL) : array;

  /**
   * Handle a trigger.
   *
   * @param string $plugin_id
   *   The trigger plugin id.
   * @param array $context_values
   *   The context values.
   * @param bool $save
   *   Whether or not to save the resulting tasks.
   *
   * @return \Drupal\task\Entity\Task[]
   *   An array of created tasks.
   */
  public function handleTrigger(string $plugin_id, array $context_values, bool $save = TRUE) : array;

}
