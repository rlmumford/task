<?php

namespace Drupal\task_job_additions;

use Drupal\task_job\Event\JobChecklistDefinitionsEvent;
use Drupal\task_job\JobChecklistExpansion;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Resolves each recorded instance against the task's live named job version.
 */
class AdditionDefinitions implements EventSubscriberInterface {

  public function __construct(protected AdditionStorage $storage) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [JobChecklistDefinitionsEvent::NAME => 'collect'];
  }

  /**
   * Adds only persisted instances belonging to this task and job version.
   */
  public function collect(JobChecklistDefinitionsEvent $event): void {
    if ($event->task->isNew()) {
      return;
    }
    foreach ($this->storage->forTask($event->task->uuid()) as $receipt) {
      if ($receipt['job'] !== $event->job->getBaseJobId() || $receipt['job_version'] !== (string) $event->job->getVersion()) {
        continue;
      }
      $definitions = JobChecklistExpansion::instance($receipt['template'], $event->job->get('checklist_templates') ?: [], self::prefix($receipt['id']));
      if (array_intersect_key($event->definitions, $definitions)) {
        throw new \InvalidArgumentException('An addition collides with an existing checklist item name.');
      }
      foreach ($definitions as &$definition) {
        $definition['derivation']['addition'] = $receipt['id'];
      }
      unset($definition);
      $event->definitions += $definitions;
    }
  }

  /**
   * Returns a stable namespace for one addition and its local item names.
   */
  public static function prefix(string $id): string {
    return 'added_' . str_replace('-', '', $id) . '__';
  }

}
