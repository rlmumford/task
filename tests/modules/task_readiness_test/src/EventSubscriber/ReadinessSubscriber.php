<?php

namespace Drupal\task_readiness_test\EventSubscriber;

use Drupal\Core\State\StateInterface;
use Drupal\task\Event\TaskReadinessEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Supplies test readiness contributions through the event dispatcher.
 */
class ReadinessSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the test subscriber.
   */
  public function __construct(protected StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [TaskReadinessEvent::class => 'onReadiness'];
  }

  /**
   * Contributes the configured test reasons.
   */
  public function onReadiness(TaskReadinessEvent $event): void {
    foreach ($this->state->get('task_readiness_test.reasons', []) as $reason) {
      $event->addReason($reason['state'], $reason['code']);
    }
  }

}
