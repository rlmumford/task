<?php

namespace Drupal\task_ui\Plugin\views\filter;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters by the job identity saved on work visible to the viewer.
 *
 * @ViewsFilter("task_ui_job")
 */
final class Job extends InOperator {

  /**
   * Validated references, keyed by their exposed selection value.
   *
   * @var array<string, array{string, string}>
   */
  private array $references = [];

  /**
   * Constructs the job filter.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly EntityTypeManagerInterface $entities, private readonly Connection $database, private readonly ModuleHandlerInterface $modules) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get('database'), $container->get('module_handler'));
  }

  /**
   * {@inheritdoc}
   */
  public function operators() {
    return array_intersect_key(parent::operators(), ['in' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    if ($this->valueOptions !== NULL) {
      return $this->valueOptions;
    }
    $this->valueOptions = [];
    $this->references = [];
    $storage = $this->entities->getStorage('task');
    // Query access applies before grouping; load one task per job identity,
    // never the complete task history or an unrestricted job catalogue.
    $rows = $storage->getAggregateQuery()->accessCheck(TRUE)
      ->exists('job.target_id')->groupBy('job.target_type')->groupBy('job.target_id')
      ->aggregate('id', 'MAX', NULL, $alias)->execute();
    foreach ($storage->loadMultiple(array_column($rows, $alias)) as $task) {
      if (!$task->access('view') || !$task->get('job')->access('view')) {
        continue;
      }
      $reference = $task->get('job')->first();
      try {
        // Resolve the stored definition, including its organization validation.
        // A current override must not replace a historical task's identity.
        $job = $reference->entity;
      }
      catch (\DomainException) {
        // Removed or invalid definitions cannot disclose a label or be chosen.
        continue;
      }
      if (!$job) {
        continue;
      }
      $key = $reference->target_type . ':' . $reference->target_id;
      $this->references[$key] = [$reference->target_type, $reference->target_id];
      // The name identifies work the actor can already read. This exposes no
      // definition contents, editor link or permission to view/edit the job.
      $label = $job->label();
      $this->modules->alter('task_ui_job_label', $label, $task, $job);
      $this->valueOptions[$key] = (string) $label;
    }
    $counts = array_count_values($this->valueOptions);
    foreach ($this->valueOptions as $key => $label) {
      if ($counts[$label] > 1) {
        [$type, $id] = $this->references[$key];
        $this->valueOptions[$key] = (string) $this->t('@label (@type: @id)', [
          '@label' => $label,
          '@type' => $this->entities->getDefinition($type)->getLabel(),
          '@id' => $id,
        ]);
      }
    }
    asort($this->valueOptions, SORT_NATURAL | SORT_FLAG_CASE);
    // Keep stale/forged selections applied, but never broaden them to all work.
    $input = $this->view->getExposedInput();
    foreach ((array) ($input[$this->options['expose']['identifier']] ?? []) as $key) {
      if (is_string($key) && $key !== '' && $key !== 'All' && !isset($this->valueOptions[$key])) {
        $this->valueOptions[$key] = $this->t('No visible work for this job');
      }
    }
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $this->getValueOptions();
    $values = (array) $this->value;
    if (!$values) {
      return;
    }
    $this->ensureMyTable();
    $conditions = $this->database->condition('OR');
    foreach ($values as $key) {
      if (!is_string($key) || !isset($this->references[$key])) {
        $this->query->addWhereExpression($this->options['group'], '1 = 0');
        return;
      }
      [$type, $id] = $this->references[$key];
      $conditions->condition($this->database->condition('AND')
        ->condition($this->tableAlias . '.job__target_type', $type)
        ->condition($this->tableAlias . '.job__target_id', $id));
    }
    $this->query->addWhere($this->options['group'], $conditions);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    $dependencies['module'][] = 'task_job';
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
