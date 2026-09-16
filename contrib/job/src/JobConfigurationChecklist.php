<?php

namespace Drupal\task_job;

use Drupal\checklist\Checklist;
use Drupal\checklist\Plugin\ChecklistType\ChecklistTypeInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\task\Entity\Task;
use Drupal\task_job\Plugin\ChecklistType\Job;

/**
 * A checklist object for configuring a job.
 *
 * This is primarily used to collect configuration contexts when configuring a
 * job.
 */
class JobConfigurationChecklist extends Checklist {

  /**
   * Create a checklist object from the job entity.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job.
   * @param \Drupal\Component\Plugin\PluginManagerInterface|null $checklist_type_manager
   *   The checklist type manager.
   *
   * @return static
   *   A constructed checklist object.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public static function createFromJob(JobInterface $job, ?PluginManagerInterface $checklist_type_manager = NULL) {
    if (!$checklist_type_manager) {
      $checklist_type_manager = \Drupal::service('plugin.manager.checklist_type');
    }

    return new static(
      $checklist_type_manager->createInstance('job', ['job' => $job])
    );
  }

  /**
   * Checklist constructor.
   *
   * @param \Drupal\checklist\Plugin\ChecklistType\ChecklistTypeInterface $type
   *   The checklist type plugin.
   */
  public function __construct(
    ChecklistTypeInterface $type
  ) {
    if (!($type instanceof Job)) {
      throw new \InvalidArgumentException('Only job configuration types are compatible with ' . static::class);
    }

    /** @var \Drupal\task_job\Plugin\ChecklistType\Job $type */
    parent::__construct(
      $type,
      Task::create([
        'job' => $type->getJob(),
      ]),
      'checklist'
    );
  }

}
