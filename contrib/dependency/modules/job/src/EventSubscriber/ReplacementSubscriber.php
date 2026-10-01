<?php

namespace Drupal\task_dependency_job\EventSubscriber;

use Drupal\task_dependency\Event\EntityReplacementEvent;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sends explicit replacement events through job trigger actions.
 */
class ReplacementSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(protected JobTriggerManagerInterface $triggers) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [EntityReplacementEvent::class => 'onReplacement'];
  }

  /**
   * Evaluates configured triggers inside the caller's replacement transaction.
   */
  public function onReplacement(EntityReplacementEvent $event): void {
    $this->triggers->handleTrigger('entity.replaced:' . $event->original->getEntityTypeId(), [
      'original' => $event->original,
      'replacement' => $event->replacement,
    ]);
  }

}
