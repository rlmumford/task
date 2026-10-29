<?php

namespace Drupal\task_ui\Plugin\views\filter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\task_ui\TaskPresenter;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters work by staff already assigned to tasks visible to the viewer.
 *
 * @ViewsFilter("task_ui_assignee")
 */
final class Assignee extends InOperator {

  /**
   * Submitted choices that no longer have a visible representative task.
   *
   * @var string[]
   */
  private array $unavailable = [];

  /**
   * Constructs the assignee filter.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly EntityTypeManagerInterface $entities, private readonly TaskPresenter $presenter) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get('task_ui.presenter'));
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    if ($this->valueOptions !== NULL) {
      return $this->valueOptions;
    }
    $this->unavailable = [];
    $storage = $this->entities->getStorage('task');
    // Query access applies before grouping. Load one representative task per
    // assignee, not every task or every user in the application.
    $rows = $storage->getAggregateQuery()->accessCheck(TRUE)
      ->condition('assignee', 0, '>')->groupBy('assignee')
      ->aggregate('id', 'MAX', NULL, $alias)->execute();
    $tasks = $storage->loadMultiple(array_column($rows, $alias));
    $this->presenter->preloadReferences($tasks, ['assignee']);
    $this->valueOptions = [];
    foreach ($tasks as $task) {
      if ($task->access('view') && $task->get('assignee')->access('view')) {
        // Reuse the board's visibility policy, including application overrides
        // for inactive staff and people who have left an organization.
        $this->valueOptions[$task->get('assignee')->target_id] = (string) $this->presenter->assignee($task);
      }
    }
    asort($this->valueOptions, SORT_NATURAL | SORT_FLAG_CASE);
    // Preserve an applied filter after its last task is reassigned. This label
    // reveals neither a user name nor whether the submitted account exists.
    $input = $this->view->getExposedInput();
    foreach ((array) ($input[$this->options['expose']['identifier']] ?? []) as $id) {
      if (is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0 && !isset($this->valueOptions[$id])) {
        $this->valueOptions[$id] = $this->t('No visible assignments');
        $this->unavailable[] = (string) $id;
      }
    }
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $this->getValueOptions();
    // A concealed field or an invalid/stale choice cannot become a search
    // oracle. Match no task for these values, even when an ID was guessed.
    $this->value = array_map(fn($id) => in_array((string) $id, $this->unavailable, TRUE) ? -1 : $id, (array) $this->value);
    parent::query();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Assignment and staff visibility can change independently of task tags.
    return 0;
  }

}
