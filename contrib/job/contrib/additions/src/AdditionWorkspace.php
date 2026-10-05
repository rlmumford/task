<?php

namespace Drupal\task_job_additions;

use Drupal\checklist\Event\ChecklistRefreshEvent;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\task\Entity\Task;
use Drupal\task_job_additions\Ajax\UpdateAdditionsCommand;
use Drupal\task_job_additions\Form\AddWorkForm;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Builds and refreshes the task addition control using the same access rules.
 */
class AdditionWorkspace implements EventSubscriberInterface {

  /**
   * Constructs the workspace integration.
   */
  public function __construct(protected AdditionManager $manager, protected FormBuilderInterface $forms) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ChecklistRefreshEvent::NAME => 'refresh'];
  }

  /**
   * Keeps an empty target when no additions are currently available.
   */
  public function build(Task $task): array {
    $templates = [];
    try {
      $templates = $this->manager->discover($task)['templates'];
    }
    catch (AccessDeniedHttpException) {
      // Viewing the workspace does not confer permission to add work.
    }
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'task-job-additions-' . $task->uuid(),
        'data-addition-choices' => hash('sha256', serialize($templates)),
      ],
      '#attached' => ['library' => ['task_job_additions/workspace']],
      '#cache' => ['max-age' => 0],
    ];
    if ($templates) {
      $build['form'] = $this->forms->getForm(AddWorkForm::class, $task);
    }
    return $build;
  }

  /**
   * Reflects committed outcomes without resetting an unchanged selection.
   */
  public function refresh(ChecklistRefreshEvent $event): void {
    $task = $event->checklist->getEntity();
    if (!$task instanceof Task || $event->checklist->getKey() !== 'checklist') {
      return;
    }
    $build = $this->build($task);
    $event->response->addCommand(new UpdateAdditionsCommand('#' . $build['#attributes']['id'], $build, $build['#attributes']['data-addition-choices']));
  }

}
