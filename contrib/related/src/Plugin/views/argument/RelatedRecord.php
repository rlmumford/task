<?php

namespace Drupal\task_related\Plugin\views\argument;

use Drupal\task_related\RelatedWork;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Restricts tasks through a subquery, preserving distinct rows and counts.
 *
 * @ViewsArgument("task_related")
 */
final class RelatedRecord extends ArgumentPluginBase {

  /**
   * Constructs the contextual filter.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly RelatedWork $work) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('task_related.work'));
  }

  /**
   * {@inheritdoc}
   */
  public function query($group_by = FALSE) {
    $this->ensureMyTable();
    $target = $this->work->target((string) $this->argument);
    if (!$target || !$target->access('view')) {
      $this->query->addWhereExpression(0, '1 = 0');
      return;
    }
    $query = $this->work->taskQuery($target);
    $this->query->addWhere(0, $this->tableAlias . '.id', $query, 'IN');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
