<?php

namespace Drupal\task_checklist;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\task\TaskReadiness;
use Drupal\task\Entity\Task;
use Drupal\task_checklist\Event\TaskChecklistEnvironmentDetectionEvent;
use Drupal\task_checklist\Event\TaskChecklistEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Process task checklists.
 */
class TaskChecklistProcessor implements TaskChecklistProcessorInterface {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * TaskChecklistProcessor constructor.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_channel_factory
   *   The logger channel factory.
   * @param \Drupal\task\TaskReadiness $readiness
   *   The task readiness evaluator.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    ModuleHandlerInterface $module_handler,
    EventDispatcherInterface $event_dispatcher,
    LoggerChannelFactoryInterface $logger_channel_factory,
    protected TaskReadiness $readiness,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->moduleHandler = $module_handler;
    $this->eventDispatcher = $event_dispatcher;
    $this->logger = $logger_channel_factory->get('task_checklist');
  }

  /**
   * {@inheritdoc}
   */
  public function processTask(Task $task) {
    if ($task->isNew()) {
      return;
    }
    // Retained task objects may predate a postponed start or resolution.
    $task = $this->entityTypeManager->getStorage('task')->loadUnchanged($task->id());
    if (!$task) {
      return;
    }
    $readiness = $this->readiness->evaluate($task);
    if ($readiness->state === 'invalid') {
      $task->resolve(Task::RESOLUTION_INVALID)->save();
      return;
    }
    if ($readiness->state === 'active') {
      if ($this->moduleHandler->moduleExists('exec_environment')) {
        $environment = new TaskChecklistEnvironmentDetectionEvent($task);
        $this->eventDispatcher->dispatch($environment, TaskChecklistEvents::DETECT_CHECKLIST_ENVIRONMENT);
        $environment->applyEnvironment();
      }

      // Process the checklist if it exists.
      if (!$task->checklist->isEmpty() && $checklist = $task->checklist->checklist) {
        /** @var \Drupal\checklist\ChecklistInterface $checklist */
        try {
          $checklist->process();
        }
        catch (\Exception $e) {
          $this->logger->error(
            "Exception when processing task checklist for task {$task->id()}.\nMessage: {$e->getMessage()}\nTrace: {$e->getTraceAsString()}"
          );
        }
      }

      // Reset the environment.
      if (isset($environment)) {
        $environment->resetEnvironment();
      }
    }
  }

}
