<?php

namespace Drupal\task_ui\Controller;

use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\task\TaskInterface;
use Drupal\task_ui\WorkQueue;
use Drupal\task_ui\TaskPresenter;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Presents shared tasks without an alternate checklist or completion workflow.
 */
final class WorkController extends ControllerBase {

  /**
   * Constructs the workspace controller.
   */
  public function __construct(private readonly WorkQueue $queue, private readonly TaskPresenter $presenter) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('task_ui.queue'), $container->get('task_ui.presenter'));
  }

  /**
   * Lists one bounded, access-checked queue.
   */
  public function listing(Request $request): array {
    return $this->queue->build($request->query->getString('queue', 'team'));
  }

  /**
   * Shows current readiness, context and the existing interactive checklist.
   */
  public function task(TaskInterface $task): array {
    if (!$task->access('view')) {
      throw new AccessDeniedHttpException();
    }
    $build = [
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['task_ui/workspace']],
      'back' => ['#type' => 'link', '#title' => $this->t('Task queues'), '#url' => Url::fromRoute('task_ui.tasks')],
      'summary' => $this->summary($task),
      'actions' => ['#type' => 'container', '#attributes' => ['class' => ['task-work-actions']]] + $this->presenter->actions($task),
    ];
    foreach (['description' => 'text_default', 'checklist' => 'checklist_interactive'] as $field => $formatter) {
      if ($task->hasField($field) && $task->get($field)->access('view')) {
        $build[$field] = $task->get($field)->view(['label' => 'hidden', 'type' => $formatter]);
      }
    }
    return $build;
  }

  /**
   * Builds metadata without instantiating or replacing working checklist forms.
   */
  private function summary(TaskInterface $task): array {
    $rows = [
      [$this->t('Status'), $this->presenter->state($task)],
      [$this->t('Assigned to'), $this->presenter->assignee($task)],
      [$this->t('Start'), $this->presenter->date($task, 'start')],
      [$this->t('Due'), $this->presenter->date($task, 'due', TRUE)],
      [$this->t('External deadline'), $this->presenter->date($task, 'deadline', TRUE)],
    ];
    foreach ($this->presenter->contextFields() as $field => $label) {
      if ($task->hasField($field) && $task->get($field)->access('view')) {
        $rows[] = [$label, $this->presenter->reference($task->get($field)->entity)];
      }
    }
    return [
      '#type' => 'container',
      '#attributes' => ['data-task-summary' => TRUE],
      'table' => ['#type' => 'table', '#rows' => $rows, '#attributes' => ['class' => ['task-work-summary']]],
    ];
  }

  /**
   * Opens a task, or refreshes its summary without replacing forms.
   */
  public function workspace(TaskInterface $task, Request $request): AjaxResponse {
    if (!$task->access('view')) {
      throw new AccessDeniedHttpException();
    }
    $response = new AjaxResponse();
    $response->headers->set('Cache-Control', 'private, no-store');
    if ($request->query->getBoolean('summary')) {
      $response->addCommand(new HtmlCommand('#task-work-content [data-task-summary]', $this->summary($task)['table']));
    }
    else {
      $build = $this->task($task);
      $build['heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#weight' => -10,
        '#attributes' => ['tabindex' => '-1', 'data-task-heading' => TRUE],
        'label' => ['#plain_text' => $task->label()],
      ];
      $build['back'] = ['#type' => 'link', '#title' => $this->t('Open task page'), '#url' => $task->toUrl()];
      $response->addCommand(new HtmlCommand('#task-work-content', $build));
    }
    return $response;
  }

}
