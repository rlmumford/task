<?php

namespace Drupal\task_checklist;

use Drupal\checklist\ChecklistReportBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\task\TaskInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Reusable task report, independent of the interactive staff workspace.
 */
final class TaskReportBuilder {

  use StringTranslationTrait;

  /**
   * Constructs the task report presenter.
   *
   * @param \Drupal\checklist\ChecklistReportBuilder $checklists
   *   Builds read-only checklist evidence.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dates
   *   Formats task creation time.
   */
  public function __construct(
    private readonly ChecklistReportBuilder $checklists,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * Shows task identity, authorized context and labeled checklist outcomes.
   *
   * @param \Drupal\task\TaskInterface $task
   *   The saved task, authorized here before reading any fields.
   * @param array $context
   *   App-authorized context render arrays, including field/reference checks.
   * @param callable|null $preview
   *   Explicit safe outcome renderer; see ChecklistReportBuilder::build().
   *
   * @return array
   *   A read-only task page without staff actions, queues or editing state.
   */
  public function build(TaskInterface $task, array $context = [], ?callable $preview = NULL): array {
    if (!$task->access('view') || !$task->get('title')->access('view')) {
      throw new AccessDeniedHttpException();
    }
    foreach (Element::children($context) as $key) {
      if (($context[$key]['#type'] ?? NULL) === 'item') {
        $context[$key]['#title_display'] = 'invisible';
      }
      $context[$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['task-report__chip', 'task-report__chip--context']],
        '#access' => $context[$key]['#access'] ?? (bool) $context[$key],
        'content' => $context[$key],
      ];
    }
    $build = [
      '#type' => 'container',
      '#title' => $task->label(),
      '#cache' => ['max-age' => 0],
      '#attributes' => ['class' => ['task-report'], 'data-task-id' => $task->id()],
      '#attached' => ['library' => ['task_checklist/report']],
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['task-report__summary']],
        'identity' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['task-report__chip', 'task-report__chip--identity']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Task'),
            '#attributes' => ['class' => ['visually-hidden']],
          ],
          'id' => ['#plain_text' => '#' . $task->id()],
        ],
      ],
      'context' => ['#type' => 'container', '#attributes' => ['class' => ['task-report__context']]] + $context,
    ];
    if ($task->get('status')->access('view')) {
      $build['summary']['status'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['task-report__chip', 'task-report__chip--status'],
          'data-status' => $task->get('status')->value,
        ],
        'field' => $task->get('status')->view(['label' => 'hidden', 'type' => 'list_default']),
      ];
    }
    if ($task->get('created')->access('view')) {
      $build['summary']['created'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['task-report__chip', 'task-report__chip--created']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Created'),
          '#attributes' => ['class' => ['visually-hidden']],
        ],
        'date' => [
          '#type' => 'html_tag',
          '#tag' => 'time',
          '#value' => $this->dates->format($task->get('created')->value, 'custom', 'm/d/Y H:i T'),
          '#attributes' => ['datetime' => gmdate('c', $task->get('created')->value)],
        ],
      ];
    }
    if (!$task->get('checklist')->isEmpty()) {
      $build['checklist'] = $this->checklists->build($task, 'checklist', 0, $preview);
    }
    return $build;
  }

}
