<?php

namespace Drupal\task_dependency\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\task\Event\TaskReadinessEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Activation waits for all matches; any invalidation match takes precedence.
 */
class ReadinessSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(protected EntityTypeManagerInterface $entities) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [TaskReadinessEvent::class => 'evaluate'];
  }

  /**
   * Contributes dependency readiness without mutating the task.
   */
  public function evaluate(TaskReadinessEvent $event): void {
    $task = $event->getTask();
    foreach ($task->get('event_dependencies') as $reference) {
      $dependency = $reference->entity;
      if ($dependency && !$dependency->isNew()) {
        $dependency = $this->entities->getStorage('task_dependency')->loadUnchanged($dependency->id());
      }
      if (!$dependency || $dependency->get('owner')->value !== $task->uuid()) {
        $event->addReason('waiting', 'dependency_missing');
      }
      elseif ($dependency->get('action')->value === 'invalidate' && $dependency->get('met')->value) {
        $event->addReason('invalid', 'dependency_invalidation');
      }
      elseif ($dependency->get('action')->value === 'activate' && !$dependency->get('met')->value) {
        $event->addReason('waiting', 'dependency_unmet');
      }
    }
  }

}
