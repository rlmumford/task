<?php

namespace Drupal\task_job\Plugin\JobTrigger;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\task\TaskInterface;
use Drupal\task_job\JobInterface;

/**
 * Interface for job trigger plugins.
 */
interface JobTriggerInterface extends PluginInspectionInterface, ConfigurableInterface, ContextAwarePluginInterface {

  /**
   * Get the task.
   *
   * @return \Drupal\task\TaskInterface|null
   *   The created task.
   */
  public function createTask() : ?TaskInterface;

  /**
   * Does this trigger have access to run.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata|null $bubbleable_metadata
   *   The cache metadata.
   *
   * @return bool
   *   TRUE if the trigger can fire, false otherwise.
   */
  public function access(?CacheableMetadata $bubbleable_metadata = NULL);

  /**
   * Get the key.
   *
   * @return string
   *   The trigger key.
   */
  public function getKey(): string;

  /**
   * Set the job.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job.
   *
   * @return $this
   */
  public function setJob(JobInterface $job): JobTriggerInterface;

  /**
   * Get the job.
   *
   * @return \Drupal\task_job\JobInterface
   *   The job.
   */
  public function getJob(): JobInterface;

  /**
   * Get the trigger label.
   *
   * @return string
   *   The trigger label.
   */
  public function getLabel();

  /**
   * Get the trigger description.
   *
   * @return string
   *   The description.
   */
  public function getDescription();

}
