<?php

namespace Drupal\task_job;

use Drupal\Core\TempStore\SharedTempStoreFactory;

/**
 * Tempstore repository for jobs.
 */
class TaskJobTempstoreRepository {

  /**
   * The shared tempstore factory.
   *
   * @var \Drupal\Core\TempStore\SharedTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * TaskJobTempstoreRepository constructor.
   *
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   */
  public function __construct(SharedTempStoreFactory $temp_store_factory) {
    $this->tempStoreFactory = $temp_store_factory;
  }

  /**
   * Get the job.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job to look for in the tempstore.
   *
   * @return \Drupal\task_job\JobInterface
   *   The job from the tempstore.
   */
  public function get(JobInterface $job) {
    $key = $this->getKey($job);
    $tempstore = $this->getTempstore($job)->get($key);
    if (!empty($tempstore['job'])) {
      $job = $tempstore['job'];
    }
    return $job;
  }

  /**
   * Check if the job is in the tempstore.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job to look for.
   *
   * @return bool
   *   True if its in the tempstore, false otherwise.
   */
  public function has(JobInterface $job) {
    $key = $this->getKey($job);
    $tempstore = $this->getTempstore($job)->get($key);
    return !empty($tempstore['job']);
  }

  /**
   * Set the job in the tempstore.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job to add to the tempstore.
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function set(JobInterface $job) {
    $key = $this->getKey($job);
    $this->getTempstore($job)->set(
      $key,
      ['job' => $job]
    );
  }

  /**
   * Delete a job from the tempstore.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job to delete from the tempstore.
   */
  public function delete(JobInterface $job) {
    $key = $this->getKey($job);
    $this->getTempstore($job)->delete($key);
  }

  /**
   * Get the key for the tempstore.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job.
   *
   * @return string
   *   The key in the tempstore.
   */
  protected function getKey(JobInterface $job) {
    return $job->id();
  }

  /**
   * Get the right tempstore.
   *
   * @param \Drupal\task_job\JobInterface $job
   *   The job to get from the temstore.
   *
   * @return \Drupal\Core\TempStore\SharedTempStore
   *   The tempstore.
   */
  protected function getTempstore(JobInterface $job) {
    $collection = 'task_job' . $job->getEntityTypeId();
    return $this->tempStoreFactory->get($collection);
  }

}
