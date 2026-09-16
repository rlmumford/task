<?php

namespace Drupal\task_job_test\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\exec_environment\EnvironmentComponentManager;
use Drupal\exec_environment\Event\EnvironmentDetectionEvent;
use Drupal\exec_environment\EventSubscriber\DetectEnvironmentSubscriberBase;
use Drupal\task_job\Event\TaskJobEvents;

/**
 * Subscriber for detecting environments.
 *
 * @package Drupal\task_job_test\EventSubscriber
 */
class TaskJobTriggerSubscriber extends DetectEnvironmentSubscriberBase {

  /**
   * The state.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * TaskJobTriggerSubscriber constructor.
   *
   * @param \Drupal\exec_environment\EnvironmentComponentManager $component_manager
   *   The component manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   */
  public function __construct(
    EnvironmentComponentManager $component_manager,
    AccountInterface $current_user,
    StateInterface $state
  ) {
    parent::__construct($component_manager, $current_user);

    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    $events[TaskJobEvents::HANDLE_TRIGGER_DETECT_ENVIRONMENT] = 'onHandleTrigger';
    return $events;
  }

  /**
   * Set the environment on handle trigger.
   *
   * @param \Drupal\exec_environment\Event\EnvironmentDetectionEvent $event
   *   The environment detection event.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function onHandleTrigger(EnvironmentDetectionEvent $event) {
    if ($this->state->get('task_job_test.handle_trigger_collection', FALSE)) {
      $event->getEnvironment()->addComponent($this->createComponent(
        'test_named_config_collection',
        [
          'collection' => $this->state->get('task_job_test.handle_trigger_collection'),
        ]
      ));
    }
  }

}
