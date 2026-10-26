<?php

namespace Drupal\task_ui;

use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\views\Views;

/**
 * Embeds configurable Views lists beside a reusable task workspace.
 */
final class WorkQueue {

  use StringTranslationTrait;

  /**
   * Constructs the board shell.
   */
  public function __construct(private readonly ModuleHandlerInterface $modules) {}

  /**
   * Builds a board; Views owns querying, filters, sorting and pagination.
   */
  public function build(string $queue = 'team'): array {
    $queues = [
      'mine' => $this->t('My work'),
      'unassigned' => $this->t('Unassigned'),
      'team' => $this->t('Team work'),
      'history' => $this->t('History'),
    ];
    $queue = isset($queues[$queue]) ? $queue : 'team';
    $links = [];
    foreach ($queues as $key => $label) {
      $links[] = Link::fromTextAndUrl($label, Url::fromRoute('task_ui.tasks', [], ['query' => ['queue' => $key]]));
    }
    $this->modules->alter('task_ui_navigation', $links);
    $view = Views::getView('task_workspace');
    $view->setDisplay($queue);
    $view->override_url = Url::fromRoute('task_ui.tasks', [], ['query' => ['queue' => $queue]]);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['task-work-board'], 'data-task-board' => TRUE],
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['task_ui/workspace']],
      'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $queues[$queue]],
      'navigation' => [
        '#theme' => 'item_list',
        '#items' => $links,
        '#attributes' => ['class' => ['task-work-navigation']],
      ],
      'layout' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['task-work-layout']],
        'queue' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['task-work-queue'], 'tabindex' => '-1'],
          'view' => $view->buildRenderable($queue),
        ],
        'panel' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['task-work-panel'],
            'data-task-panel' => TRUE,
            'hidden' => TRUE,
            'role' => 'region',
            'aria-label' => $this->t('Selected task'),
          ],
          'close' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Close task'),
            '#attributes' => ['type' => 'button', 'data-task-close' => TRUE],
          ],
          'status' => ['#type' => 'container', '#attributes' => ['data-task-status' => TRUE, 'role' => 'status']],
          'content' => ['#type' => 'container', '#attributes' => ['id' => 'task-work-content']],
        ],
      ],
    ];
  }

}
