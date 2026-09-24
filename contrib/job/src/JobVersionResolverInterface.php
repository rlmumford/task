<?php

namespace Drupal\task_job;

/**
 * Resolves task jobs by logical ID and optional named version.
 */
interface JobVersionResolverInterface {

  /**
   * Load a job version, or the latest version when none is supplied.
   *
   * @param string $job_id
   *   The logical job ID.
   * @param string|null $version
   *   The explicit version, or NULL for the latest version.
   *
   * @return \Drupal\task_job\JobInterface|null
   *   The resolved job.
   */
  public function load(string $job_id, ?string $version = NULL): ?JobInterface;

  /**
   * Load only the clean version, ignoring any dirty working copy.
   */
  public function loadClean(string $job_id, string $version): ?JobInterface;

  /**
   * Load the latest version of a job.
   */
  public function loadLatest(string $job_id): ?JobInterface;

  /**
   * List the versioned job entities for a logical job ID.
   *
   * @return \Drupal\task_job\JobInterface[]
   *   Versioned jobs keyed by entity ID, newest first where versions are
   *   numeric and otherwise in descending lexical order.
   */
  public function listVersions(string $job_id): array;

  /**
   * List dirty working copies for a logical job ID.
   *
   * @return \Drupal\task_job\JobInterface[]
   *   Dirty jobs keyed by entity ID.
   */
  public function listDirtyVersions(string $job_id): array;

  /**
   * Create an unsaved version from a job definition.
   */
  public function createVersion(JobInterface $job, string $version): JobInterface;

  /**
   * Create an unsaved dirty working copy of a named version.
   */
  public function createDirtyVersion(JobInterface $job): JobInterface;

}
