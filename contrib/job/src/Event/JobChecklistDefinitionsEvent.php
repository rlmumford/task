<?php

namespace Drupal\task_job\Event;

use Drupal\task\Entity\Task;
use Drupal\task_job\JobInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Resolves trusted job-derived additions for a task's definition catalog.
 */
final class JobChecklistDefinitionsEvent extends Event {

  public const NAME = 'task_job.checklist_definitions';

  public function __construct(
    public readonly Task $task,
    public readonly JobInterface $job,
    public array $definitions,
  ) {}

}
