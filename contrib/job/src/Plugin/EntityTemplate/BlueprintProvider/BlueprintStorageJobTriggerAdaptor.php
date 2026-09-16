<?php

namespace Drupal\task_job\Plugin\EntityTemplate\BlueprintProvider;

use Drupal\Core\Session\AccountInterface;
use Drupal\entity_template\BlueprintStorageInterface;
use Drupal\entity_template\BlueprintStorageTrait;
use Drupal\entity_template\Plugin\EntityTemplate\BlueprintProvider\BlueprintProviderInterface;
use Drupal\task_job\JobInterface;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface;

/**
 * Blueprint storage adaptor for job triggers.
 */
class BlueprintStorageJobTriggerAdaptor extends BlueprintJobTriggerAdaptor implements BlueprintStorageInterface {
  use BlueprintStorageTrait;

  /**
   * BlueprintStorageJobTriggerAdaptor constructor.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job.
   * @param \Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface $trigger
   *   The job trigger.
   * @param \Drupal\entity_template\Plugin\EntityTemplate\BlueprintProvider\BlueprintProviderInterface $provider
   *   The blueprint provider.
   */
  public function __construct(
    JobInterface $job,
    JobTriggerInterface $trigger,
    BlueprintProviderInterface $provider
  ) {
    $this->provider = $provider;

    parent::__construct($job, $trigger);
  }

  /**
   * {@inheritdoc}
   */
  public function access($operation, AccountInterface $account, $return_as_object = TRUE) {
    return $this->getJob()->access($operation, $account, $return_as_object);
  }

}
