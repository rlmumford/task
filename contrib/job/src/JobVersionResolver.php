<?php

namespace Drupal\task_job;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Default resolver for versioned task job configuration entities.
 */
class JobVersionResolver implements JobVersionResolverInterface {

  /**
   * The job storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;

  /**
   * Constructs the resolver.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->storage = $entity_type_manager->getStorage('task_job');
  }

  /**
   * {@inheritdoc}
   */
  public function load(string $job_id, ?string $version = NULL): ?JobInterface {
    if ($version !== NULL) {
      $dirty = $this->storage->load(JobVersionId::buildDirty($job_id, $version));
      if ($dirty instanceof JobInterface) {
        return $dirty;
      }

      return $this->loadClean($job_id, $version);
    }

    return $this->loadLatest($job_id);
  }

  /**
   * {@inheritdoc}
   */
  public function loadClean(string $job_id, string $version): ?JobInterface {
    $entity = $this->storage->load(JobVersionId::build($job_id, $version));
    return $entity instanceof JobInterface ? $entity : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadLatest(string $job_id): ?JobInterface {
    $versions = $this->listVersions($job_id);
    if ($versions) {
      $latest = reset($versions);
      $dirty = $this->storage->load(JobVersionId::buildDirty(
        $job_id,
        (string) $latest->getVersion()
      ));
      return $dirty instanceof JobInterface ? $dirty : $latest;
    }

    $entity = $this->storage->load($job_id);
    return $entity instanceof JobInterface ? $entity : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function listVersions(string $job_id): array {
    $versions = [];

    foreach ($this->storage->loadMultiple() as $entity) {
      if (!$entity instanceof JobInterface
        || JobVersionId::base($entity->id()) !== $job_id
        || !JobVersionId::isVersioned($entity->id())
        || JobVersionId::isDirty($entity->id())) {
        continue;
      }

      $versions[$entity->id()] = $entity;
    }

    uksort($versions, static function (string $a, string $b): int {
      $a_version = JobVersionId::version($a);
      $b_version = JobVersionId::version($b);

      if (is_numeric($a_version) && is_numeric($b_version)) {
        return (int) $b_version <=> (int) $a_version;
      }

      return strnatcasecmp((string) $b_version, (string) $a_version);
    });

    return $versions;
  }

  /**
   * {@inheritdoc}
   */
  public function listDirtyVersions(string $job_id): array {
    $versions = [];

    foreach ($this->storage->loadMultiple() as $entity) {
      if (!$entity instanceof JobInterface
        || JobVersionId::base($entity->id()) !== $job_id
        || !JobVersionId::isDirty($entity->id())) {
        continue;
      }

      $versions[$entity->id()] = $entity;
    }

    return $versions;
  }

  /**
   * {@inheritdoc}
   */
  public function createVersion(JobInterface $job, string $version): JobInterface {
    $base_id = JobVersionId::base($job->id());
    $values = $job->toArray();
    $values['id'] = JobVersionId::build($base_id, $version);
    $values['version'] = $version;
    $values['version_of'] = $base_id;
    $values['dirty'] = FALSE;

    return $this->storage->create($values);
  }

  /**
   * {@inheritdoc}
   */
  public function createDirtyVersion(JobInterface $job): JobInterface {
    if ($job->isDirty()) {
      throw new \InvalidArgumentException('A dirty working copy cannot be used as the source of another dirty copy.');
    }

    $version = $job->getVersion();
    if ($version === NULL) {
      throw new \InvalidArgumentException('A dirty version must be based on a named job version.');
    }

    $base_id = JobVersionId::base($job->id());
    $values = $job->toArray();
    $values['id'] = JobVersionId::buildDirty($base_id, $version);
    $values['version'] = $version;
    $values['version_of'] = $base_id;
    $values['dirty'] = TRUE;

    return $this->storage->create($values);
  }

}
