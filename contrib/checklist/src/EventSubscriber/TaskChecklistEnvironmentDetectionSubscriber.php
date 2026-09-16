<?php

namespace Drupal\task_checklist;

use Drupal\exec_environment\EventSubscriber\DetectEnvironmentSubscriberBase;
use Drupal\task_checklist\Event\TaskChecklistEnvironmentDetectionEvent;
use Drupal\task_checklist\Event\TaskChecklistEvents;

/**
 * Subscriber for detecting task processing environments.
 *
 * @package Drupal\task_checklist
 */
class TaskChecklistEnvironmentDetectionSubscriber extends DetectEnvironmentSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[TaskChecklistEvents::DETECT_CHECKLIST_ENVIRONMENT] = 'onDetectChecklistEnvironment';
    return $events;
  }

  /**
   * Add components to the environment when running a checklist.
   *
   * @param \Drupal\task_checklist\Event\TaskChecklistEnvironmentDetectionEvent $event
   *   The detection event.
   */
  public function onDetectChecklistEnvironment(TaskChecklistEnvironmentDetectionEvent $event) {
    if ($assignee = $event->getTask()->assignee->entity) {
      $event->getEnvironment()->addComponent($this->createComponent(
        'configured_current_user',
        ['user' => $assignee]
      ));
    }
  }

}
