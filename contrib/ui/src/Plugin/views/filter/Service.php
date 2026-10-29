<?php

namespace Drupal\task_ui\Plugin\views\filter;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\filter\StringFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters by one accessible service before pagination.
 *
 * @ViewsFilter("task_ui_service")
 */
final class Service extends StringFilter {

  /**
   * Constructs the service selector.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $connection, private readonly EntityTypeManagerInterface $entities) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $connection);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('database'), $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function operators() {
    return array_intersect_key(parent::operators(), ['=' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    parent::valueForm($form, $form_state);
    $form['value']['#type'] = 'entity_autocomplete';
    $form['value']['#target_type'] = 'service';
    $form['value']['#selection_handler'] = 'task_ui_service';
    $form['value']['#process_default_value'] = FALSE;
    // Query-time access is authoritative, including stale or forged URLs.
    $form['value']['#validate_reference'] = FALSE;
    $form['value']['#description'] = $this->t('Find a service by name.');
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // EntityAutocomplete validation converts an exact typed service name to
    // an ID before Views calls query(). Label (ID) also supports direct input.
    $value = trim((string) $this->value);
    if ($value === '') {
      return;
    }
    $id = ctype_digit($value) ? $value : EntityAutocomplete::extractEntityIdFromAutocompleteInput($value);
    $service = $id && ctype_digit((string) $id) ? $this->entities->getStorage('service')->load($id) : NULL;
    if (!$service || !$service->access('view')) {
      $this->query->addWhereExpression($this->options['group'], '1 = 0');
      return;
    }
    $this->ensureMyTable();
    $this->query->addWhere($this->options['group'], $this->tableAlias . '.' . $this->realField, $service->id());
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    $dependencies['module'][] = 'service';
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
