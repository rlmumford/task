<?php

namespace Drupal\task_job\Plugin\EntityTemplate\Component;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\entity_template\TemplateResult;
use Drupal\typed_data\Widget\FormWidgetManagerInterface;
use Drupal\typed_data_reference\TypedDataCastingTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity Template Component for setting task context.
 *
 * @EntityTemplateComponent(
 *   id = "task_context.widget_input",
 *   label = "Task Context (Input)",
 *   category = "Task Context",
 *   entity_type_id = "task",
 *   applies_to = { "entity:task" },
 *   forms = {
 *     "add" = "Drupal\task_job\PluginForm\EntityTemplate\Component\TaskContextAddForm",
 *   }
 * )
 *
 * @package Drupal\task_job\Plugin\EntityTemplate\Component
 */
class TaskContextWidgetInput extends TaskContextBase {
  use TypedDataCastingTrait;

  /**
   * The form widget manager.
   *
   * @var \Drupal\entity_template_ui\TypedDataFormWidgetManager
   */
  protected $widgetManager;

  /**
   * The typed data manager service.
   *
   * @var \Drupal\Core\TypedData\TypedDataManagerInterface
   */
  protected $typedDataManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.typed_data_form_widget'),
      $container->get('typed_data_manager')
    );
  }

  /**
   * TaskContextWidgetInput constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\typed_data\Widget\FormWidgetManagerInterface $widget_manager
   *   The widget manager.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typed_data_manager
   *   The typed data manager.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    FormWidgetManagerInterface $widget_manager,
    TypedDataManagerInterface $typed_data_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager);

    $this->widgetManager = $widget_manager;
    $this->typedDataManager = $typed_data_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function apply(EntityInterface $entity, TemplateResult $result) {
    if (isset($this->configuration['value'])) {
      $target_definition = $this->getTaskContextDefinitions()[$this->configuration['task_context']];
      $entity->get('context')->{$this->configuration['task_context']} =
        $this->upcastValue($this->configuration['value'], $target_definition)->getValue();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $definitions = $this->getTaskContextDefinitions();

    if (empty($definitions[$this->configuration['task_context']])) {
      $form['message']['#markup'] = new TranslatableMarkup(
        'The @context context is not defined.',
        ['@context' => $this->configuration['task_context']]
      );
      return parent::buildConfigurationForm($form, $form_state);
    }

    $target_data_definition = $definitions[$this->configuration['task_context']];
    if ($widget = $this->widgetManager->getFormWidget($target_data_definition)) {
      $target_data = $this->typedDataManager->createInstance(
        $target_data_definition->getDataType(),
        [
          'data_definition' => $target_data_definition,
          'name' => 'value',
          'parent' => NULL,
        ]
      );
      if (isset($this->configuration['value'])) {
        $this->upcastValue(
          $this->configuration['value'],
          $target_data_definition,
          $target_data
        );
      }
      $form['value'] = [
        '#widget' => $widget,
        '#typed_data' => $target_data,
      ];
      $form['value'] += $widget->form(
        $target_data,
        SubformState::createForSubform($form['value'], $form, $form_state)
      );
    }
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $widget = $form['value']['#widget'];
    $target_data = $form['value']['#typed_data'];

    $widget->extractFormValues(
      $target_data,
      SubformState::createForSubform($form['value'], $form, $form_state)
    );

    $this->configuration['value'] = $this->downcastData($target_data);

    parent::submitConfigurationForm($form, $form_state);
  }

}
