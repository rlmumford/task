<?php

namespace Drupal\task_ui\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\task_ui\TaskPresenter;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Uses shared presentation for computed or access-sensitive task columns.
 *
 * @ViewsField("task_ui_detail")
 */
final class TaskDetail extends FieldPluginBase {

  /**
   * Constructs the field presenter.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly TaskPresenter $presenter) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('task_ui.presenter'));
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$values) {
    $fields = match ($this->definition['component']) {
      'context' => array_keys($this->presenter->contextFields()),
      'assignee' => ['assignee'],
      default => [],
    };
    if ($fields) {
      $this->presenter->preloadReferences(array_filter(array_map(fn(ResultRow $row) => $this->getEntity($row), $values)), $fields);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $task = $this->getEntity($values);
    if (!$task || !$task->access('view')) {
      return [];
    }
    $component = $this->definition['component'];
    if ($component === 'open') {
      $url = $task->toUrl();
      $url->setOption('attributes', [
        'data-task-workspace-url' => Url::fromRoute('task_ui.workspace', ['task' => $task->id()])->toString(),
        'data-task-id' => $task->id(),
      ]);
      return Link::fromTextAndUrl($task->label(), $url)->toRenderable();
    }
    if ($component === 'context') {
      $items = [];
      foreach ($this->presenter->contextFields() as $field => $label) {
        if ($task->hasField($field) && $task->get($field)->access('view')) {
          $items[] = $this->presenter->reference($task->get($field)->entity);
        }
      }
      return ['#theme' => 'item_list', '#items' => $items];
    }
    if ($component === 'actions') {
      return $this->presenter->actions($task);
    }
    $text = match ($component) {
      'state' => $this->presenter->state($task),
      'assignee' => $this->presenter->assignee($task),
      'due', 'deadline' => $this->presenter->date($task, $component, TRUE),
    };
    return ['#plain_text' => $text];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
