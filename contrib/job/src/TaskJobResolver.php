<?php

namespace Drupal\task_job;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Resolves the task's single authoritative job reference.
 */
class TaskJobResolver {

  /**
   * Constructs the job reference resolver.
   */
  public function __construct(protected JobVersionResolverInterface $versions, protected ModuleHandlerInterface $modules, protected EntityTypeManagerInterface $entities) {}

  /**
   * Resolves a saved identity; providers may validate it, never substitute it.
   */
  public function resolve(FieldableEntityInterface $task, bool $allow_preview = TRUE): ?JobInterface {
    if (!$task->hasField('job') || $task->get('job')->isEmpty()) {
      return NULL;
    }
    if (count($task->get('job')) !== 1) {
      throw new \DomainException('A task must reference exactly one job definition.');
    }
    $reference = $task->get('job')->first();
    if ($reference->target_id === NULL || $reference->target_id === '') {
      throw new \DomainException('A task job reference requires a job ID.');
    }
    $type = $reference->target_type;
    $definition = $type ? $this->entities->getDefinition($type, FALSE) : NULL;
    if (!$definition || !$definition->entityClassImplements(JobInterface::class)) {
      throw new \DomainException('A task job reference must identify a supported job entity type.');
    }
    $version = $reference->version;
    $preview = $allow_preview ? $reference->getPreview() : NULL;
    if ($type === 'task_job') {
      $job = $preview ?? $this->versions->load($reference->target_id, $version);
    }
    elseif ($definition->isRevisionable()) {
      if (!$version || !ctype_digit((string) $version)) {
        throw new \DomainException('A content job reference requires a saved revision.');
      }
      $job = $preview ?? $this->entities->getStorage($type)->loadRevision($version);
      if (!$job instanceof RevisionableInterface || (string) $job->getRevisionId() !== (string) $version) {
        throw new \DomainException('The referenced job revision does not exist.');
      }
    }
    else {
      throw new \DomainException('Content job definitions must support immutable revisions.');
    }
    if (!$job instanceof JobInterface || $job->getEntityTypeId() !== $type ||
      (string) ($type === 'task_job' ? $job->getBaseJobId() : $job->id()) !== (string) $reference->target_id) {
      throw new \DomainException('The referenced job definition does not exist or does not match its identity.');
    }
    $this->modules->invokeAll('task_job_reference_validate', [$task, $job]);
    return $job;
  }

}
