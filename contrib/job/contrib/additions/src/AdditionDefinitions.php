<?php

namespace Drupal\task_job_additions;

use Drupal\task_job\Event\JobChecklistDefinitionsEvent;
use Drupal\task_job\JobChecklistExpansion;
use Drupal\task_job\JobInterface;
use Drupal\checklist\ChecklistContextMapping;
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
      $definitions = JobChecklistExpansion::instance($receipt['template'], $event->job->get('checklist_templates') ?: [], self::prefix($receipt['id']), self::contextMapping($event->job, $receipt['template'], $receipt['exposure']));
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
   * Reads authored buttons, retaining the original single-button configuration.
   */
  public static function exposures(array $template): array {
    return $template['exposures'] ?? [
      'default' => [
        'enabled' => !empty($template['allow_addition']),
        'label' => $template['addition_label'] ?? '',
        'condition' => $template['addition_condition'] ?? [],
        'context_mapping' => $template['addition_context_mapping'] ?? [],
      ],
    ];
  }

  /**
   * Only the invoked template's declared inputs can be mapped by a button.
   */
  public static function contextMapping(JobInterface $job, string $template, string $exposure = 'default'): array {
    $definition = $job->get('checklist_templates')[$template] ?? [];
    $mapping = self::exposures($definition)[$exposure]['context_mapping'] ?? [];
    if (array_diff_key($mapping, ChecklistContextMapping::definitions($definition['context'] ?? []))) {
      throw new \InvalidArgumentException('Addition context mappings can only target declared template inputs.');
    }
    return $mapping;
  }

  /**
   * Returns a stable namespace for one addition and its local item names.
   */
  public static function prefix(string $id): string {
    return 'added_' . str_replace('-', '', $id) . '__';
  }

}
