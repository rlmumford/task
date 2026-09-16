<?php

namespace Drupal\task_job\Plugin\JobTrigger;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\task_job\JobInterface;

/**
 * Lazy collection of job triggers.
 *
 * @method \Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface get($instance_id)
 */
class LazyJobTriggerCollection extends DefaultLazyPluginCollection {

  /**
   * The job.
   *
   * @var \Drupal\task_job\JobInterface
   */
  protected $job;

  /**
   * LazyJobTriggerCollection constructor.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $manager
   *   The plugin manager.
   * @param array $configurations
   *   The configurations.
   */
  public function __construct(
    JobInterface $job,
    PluginManagerInterface $manager,
    array $configurations = []
  ) {
    $this->job = $job;

    parent::__construct($manager, $configurations);
  }

  /**
   * {@inheritdoc}
   */
  protected function initializePlugin($instance_id) {
    $configuration = $this->configurations[$instance_id] ?? [];
    if (!isset($configuration[$this->pluginKey])) {
      throw new PluginNotFoundException($instance_id);
    }

    $this->set(
      $instance_id,
      $this->manager
        ->createInstance($configuration[$this->pluginKey], $configuration)
        ->setJob($this->job)
    );
  }

}
