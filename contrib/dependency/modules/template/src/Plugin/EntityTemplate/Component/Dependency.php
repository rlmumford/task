<?php

namespace Drupal\task_dependency_template\Plugin\EntityTemplate\Component;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\entity_template\Plugin\EntityTemplate\Component\ComponentBase;
use Drupal\entity_template\Plugin\EntityTemplate\Component\TemplateContextAwareComponentInterface;
use Drupal\entity_template\Plugin\EntityTemplate\Component\TemplateContextAwareComponentTrait;
use Drupal\entity_template\TemplateResult;
use Drupal\task\TaskInterface;
use Drupal\task_dependency\DependencyManager;
use Drupal\task_dependency\TriggerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Binds a new task's dependency to a context of its creation template.
 *
 * @EntityTemplateComponent(
 *   id = "task_dependency",
 *   label = @Translation("Task dependency"),
 *   category = @Translation("Tasks"),
 *   applies_to = {"entity:task"}
 * )
 */
class Dependency extends ComponentBase implements TemplateContextAwareComponentInterface, ContextAwarePluginInterface, ContainerFactoryPluginInterface {
  use TemplateContextAwareComponentTrait;
  use ContextAwarePluginTrait;

  /**
   * Constructs the template component.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected DependencyManager $dependencies, protected ContextHandlerInterface $contextHandler, protected TriggerManager $triggers) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('task_dependency.manager'), $container->get('context.handler'), $container->get('plugin.manager.task_dependency.trigger'));
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinitions() {
    return $this->triggers->getDefinition($this->getConfiguration()['trigger'])['context_definitions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinition($name) {
    return $this->getContextDefinitions()[$name] ?? throw new \InvalidArgumentException('Unknown dependency context.');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'trigger' => 'task.resolved',
      'action' => 'activate',
      'field' => 'status',
      'property' => 'value',
      'value' => '',
      'context_mapping' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(EntityInterface $entity, TemplateResult $result) {
    if (!$entity instanceof TaskInterface) {
      throw new \InvalidArgumentException('Dependencies can only be attached to tasks.');
    }
    $this->context = [];
    $this->contextHandler->applyContextMapping($this, $this->getContextProvidingTemplate()->getContexts());
    $configuration = $this->getConfiguration();
    $dependency = $this->dependencies->create($entity, $configuration['trigger'], array_intersect_key($configuration, array_flip([
      'field',
      'property',
      'value',
    ])), $configuration['action'], $this->getContextValue($this->triggers->bindingDefinition($configuration['trigger'])[0]));
    $entity->get('event_dependencies')->appendItem(['entity' => $dependency]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $config = $this->getConfiguration();
    $input = $form_state->getUserInput() ?? [];
    $parents = $form['#parents'] ?? [];
    $selected = NestedArray::getValue($input, array_merge($parents, ['trigger']));
    if (is_string($selected) && $this->triggers->hasDefinition($selected)) {
      $config['trigger'] = $selected;
    }
    $wrapper = 'dependency-template-' . substr(hash('sha256', implode(':', $parents)), 0, 16);
    $form['#prefix'] = '<div id="' . $wrapper . '">';
    $form['#suffix'] = '</div>';
    $form['trigger'] = [
      '#type' => 'select',
      '#title' => $this->t('Event'),
      '#options' => $this->triggers->options(),
      '#default_value' => $config['trigger'],
      '#ajax' => ['callback' => [static::class, 'rebuildConfiguration'], 'wrapper' => $wrapper],
    ];
    $form['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#options' => [
        'activate' => $this->t('Activate'),
        'invalidate' => $this->t('Invalidate'),
      ],
      '#default_value' => $config['action'],
    ];
    foreach ([
      'field' => 'State field',
      'property' => 'State property',
      'value' => 'Qualifying value',
    ] as $key => $label) {
      $form[$key] = ['#type' => 'textfield', '#title' => $label, '#default_value' => $config[$key]];
    }
    $form['context_mapping']['#tree'] = TRUE;
    [$name, $definition] = $this->triggers->bindingDefinition($config['trigger']);
    $form['context_mapping'][$name] = [
      '#type' => 'textfield',
      '#title' => $definition->getLabel(),
      '#description' => $this->t('Use a template context or Typed Data Plus selector, for example entity_current. The selected saved entity becomes the indexed binding.'),
      '#default_value' => $config['context_mapping'][$name] ?? '',
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * Rebuilds the context mapping for the newly selected event definition.
   */
  public static function rebuildConfiguration(array $form, FormStateInterface $form_state): array {
    return NestedArray::getValue($form, array_slice($form_state->getTriggeringElement()['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    foreach (array_keys($this->defaultConfiguration()) as $key) {
      $this->configuration[$key] = $form_state->getValue($key);
    }
  }

}
