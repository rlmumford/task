<?php

namespace Drupal\task_related\Plugin\views\field;

use Drupal\task_related\LinkPresentation;
use Drupal\task_related\RelatedWork;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows saved context and outcome links without multiplying task rows.
 *
 * @ViewsField("task_related")
 */
final class RecordRelations extends FieldPluginBase {

  /**
   * Constructs the relationship field.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly LinkPresentation $presentation, private readonly RelatedWork $work) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('task_related.presentation'), $container->get('task_related.work'));
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $task = $this->getEntity($values);
    $target = $this->work->target((string) ($this->view->args[0] ?? ''));
    return $task && $target ? $this->presentation->relations($task, $target) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
