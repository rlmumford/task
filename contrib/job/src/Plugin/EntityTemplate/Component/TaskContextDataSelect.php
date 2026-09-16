<?php

namespace Drupal\task_job\Plugin\EntityTemplate\Component;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\entity_template\Plugin\EntityTemplate\Component\DataSelectComponentTrait;
use Drupal\entity_template\TemplateResult;
use Drupal\typed_data\DataFetcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity Template Component for setting task context.
 *
 * @EntityTemplateComponent(
 *   id = "task_context.data_select",
 *   label = "Task Context (Select)",
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
class TaskContextDataSelect extends TaskContextBase {
  use DataSelectComponentTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('typed_data.data_fetcher')
    );
  }

  /**
   * TaskContextDataSelect constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\typed_data\DataFetcherInterface $data_fetcher
   *   The data fetcher.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    DataFetcherInterface $data_fetcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager);

    $this->dataFetcher = $data_fetcher;
  }

  /**
   * {@inheritdoc}
   */
  public function apply(EntityInterface $entity, TemplateResult $result) {
    $task_context = $this->configuration['task_context'];
    $selector = $this->configuration['selector'];

    try {
      $data = $this->selectData($selector);
      if ($data && $data->getValue()) {
        $entity->get('context')->{$task_context} = $data->getValue();
      }
    }
    catch (MissingDataException $exception) {
      $result->addMessage($exception->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $data_definitions = $this->getTaskContextDefinitions();
    if (!empty($data_definitions)) {
      $form['selector'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Select Data'),
        '#default_value' => $this->configuration['selector'] ?? '',
        '#attributes' => [
          'class' => ['entity-template-ui-autocomplete'],
        ],
        '#attached' => [
          'library' => [
            'entity_template_ui/entity_template_ui.autocomplete',
          ],
        ],
      ] + $this->getTemplateUi($this->template)
        ->dataSelectAutocompleteElement(
          $this->configuration['uuid']
        );
    }
    else {
      $form['message'] = [
        '#markup' => new TranslatableMarkup('This component only works on job trigger plugins.'),
      ];
    }

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $selector = $form_state->getValue('selector');
    [$context, $path] = explode('.', $selector . '.', 2);

    if (empty($path)) {
      $selected_data_def = $this->getContextProvidingTemplate()
        ->getContextDefinition($context)
        ->getDataDefinition();
    }
    else {
      $path = substr($path, 0, -1);
      $selected_data_def = $this->dataFetcher->fetchDefinitionByPropertyPath(
        $this->getContextProvidingTemplate()->getContextDefinition($context)->getDataDefinition(),
        $path
      );
    }

    if (!$this->definitionIsCompatible($form_state->getValue('task_context'), $selected_data_def)) {
      $form_state->setError($form['selector'], $this->t('The selected data is not compatible with this context.'));
    }

    parent::validateConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['selector'] = $form_state->getValue('selector');

    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * Check whether the data definition is compatible with this field.
   *
   * @param string $key
   *   The task context key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   True of the selected data are compatable.
   */
  protected function definitionIsCompatible(string $key, DataDefinitionInterface $definition) {
    if ($definition->getDataType() === 'list') {
      /** @var \Drupal\Core\TypedData\ListDataDefinitionInterface $definition */
      // Check the compatability of the item definition.
      $definition = $definition->getItemDefinition();
    }

    $context_definition = $this->getTaskContextDefinitions()[$key];
    $data_types = [
      $context_definition->getDataType(),
    ];
    if (
      $context_definition instanceof ComplexDataDefinitionInterface &&
      $main_property = $context_definition->getMainPropertyName()
    ) {
      $data_types[] = $context_definition
        ->getPropertyDefinition($main_property)
        ->getDataType();
    }

    return in_array($definition->getDataType(), $data_types);
  }

}
