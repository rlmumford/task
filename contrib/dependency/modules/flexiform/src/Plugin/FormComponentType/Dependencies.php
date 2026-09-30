<?php

namespace Drupal\task_dependency_flexiform\Plugin\FormComponentType;

use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\flexiform\Api\ApiComponentInterface;
use Drupal\flexiform\Api\InvalidInputException;
use Drupal\flexiform\FormComponent\ContainerFactoryFormComponentInterface;
use Drupal\flexiform\FormComponent\FormComponentBase;
use Drupal\flexiform\FormInterface;
use Drupal\task\TaskInterface;
use Drupal\task_dependency\DependencyEditor;
use Drupal\task_dependency\TriggerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Uses the same working-copy contract for HTML and API dependency editing.
 */
class Dependencies extends FormComponentBase implements ContainerFactoryFormComponentInterface, ApiComponentInterface {

  /**
   * Constructs the component.
   */
  public function __construct($name, array $options, FormInterface $display, protected DependencyEditor $editor, protected WidgetPluginManager $widgets, protected TriggerManager $triggers) {
    parent::__construct($name, $options, $display);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $name, array $options, FormInterface $display) {
    return new static($name, $options, $display, $container->get('task_dependency.editor'), $container->get('plugin.manager.field.widget'), $container->get('plugin.manager.task_dependency.trigger'));
  }

  /**
   * Resolves the task from the form's named context.
   */
  protected function task(): TaskInterface {
    $task = $this->getFormDataManager()->getContext($this->options['context'])->getContextValue();
    if (!$task instanceof TaskInterface) {
      throw new \InvalidArgumentException('The dependency component requires a task context.');
    }
    $this->editor->authorize($task);
    return $task;
  }

  /**
   * Creates the shared field widget without saving the task.
   */
  protected function widget() {
    return $this->widgets->getInstance([
      'field_definition' => $this->task()->get('event_dependencies')->getFieldDefinition(),
      'configuration' => [
        'type' => 'task_dependencies',
        'settings' => [],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDataPaths(): array {
    return [$this->options['context'] => ['event_dependencies']];
  }

  /**
   * {@inheritdoc}
   */
  public function render(array &$form, FormStateInterface $form_state, RendererInterface $renderer) {
    $part = ['#parents' => array_merge($form['#parents'], [$this->name]), '#tree' => TRUE];
    $part['event_dependencies'] = $this->widget()->form($this->task()->get('event_dependencies'), $part, $form_state);
    $form[$this->name] = $part;
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(array $form, FormStateInterface $form_state) {
    $this->widget()->extractFormValues($this->task()->get('event_dependencies'), $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function describeApi(array $state): array {
    $properties = [];
    foreach (['id', 'trigger', 'action', 'entity_id', 'field', 'property', 'value'] as $key) {
      $properties[$key] = ['type' => 'string'];
    }
    $properties['trigger']['enum'] = array_keys($this->triggers->options());
    $properties['action']['enum'] = ['activate', 'invalidate'];
    return [
      'schema' => [
        'type' => 'array',
        'items' => [
          'type' => 'object',
          'additionalProperties' => FALSE,
          'properties' => $properties,
          'required' => [
            'trigger',
            'action',
            'entity_id',
          ],
        ],
      ],
      'data' => $this->editor->values($this->task()),
      'ui' => ['label' => 'Task dependencies'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function applyApiInput(mixed $value, array &$state): void {
    if (!is_array($value) || !array_is_list($value)) {
      throw new InvalidInputException([$this->name => 'Expected a list of dependencies.']);
    }
    try {
      $task = $this->task();
      $items = $this->editor->prepare($task, $value);
      $task->set('event_dependencies', $items);
    }
    catch (\InvalidArgumentException $exception) {
      throw new InvalidInputException([$this->name => $exception->getMessage()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [
      'context' => [
        '#type' => 'textfield',
        '#title' => 'Task context',
        '#default_value' => $this->options['context'] ?? 'task',
        '#required' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsFormSubmit($values, array $form, FormStateInterface $form_state) {
    return ['context' => $values['context']];
  }

}
