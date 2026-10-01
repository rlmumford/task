<?php

namespace Drupal\task_dependency_job\Plugin\JobTriggerAction;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\task_dependency\DependencyManager;
use Drupal\task_dependency\TriggerManager;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface;
use Drupal\task_job\TriggerActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Moves selected outstanding prerequisites when the source reports replacement.
 *
 * @JobTriggerAction(
 *   id = "retarget_dependencies",
 *   label = @Translation("Retarget matching dependencies")
 * )
 */
class RetargetDependencies extends TriggerActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the action.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected DependencyManager $dependencies, protected TriggerManager $events, protected EntityTypeManagerInterface $entities, protected ContextHandlerInterface $contextHandler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('task_dependency.manager'), $container->get('plugin.manager.task_dependency.trigger'), $container->get('entity_type.manager'), $container->get('context.handler'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'dependency_trigger' => 'task.resolved',
      'dependency_action' => 'activate',
    ] + parent::defaultConfiguration();
  }

  /**
   * The watched event determines both entity types.
   */
  public function getContextDefinitions() {
    [, $definition] = $this->events->bindingDefinition($this->configuration['dependency_trigger']);
    return [
      'original' => (clone $definition)->setLabel($this->t('Original prerequisite')),
      'replacement' => (clone $definition)->setLabel($this->t('Replacement prerequisite')),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinition($name) {
    return $this->getContextDefinitions()[$name] ?? throw new \InvalidArgumentException('Unknown replacement context.');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(JobTriggerInterface $trigger, bool $save): array {
    if (!$save) {
      return [];
    }
    $original = $this->getContextValue('original');
    $replacement = $this->getContextValue('replacement');
    if (!in_array($this->configuration['dependency_action'], ['activate', 'invalidate'], TRUE)) {
      throw new \InvalidArgumentException('Select the dependency effect to migrate.');
    }
    $candidates = $this->entities->getStorage('task_dependency')->watching($original->getEntityTypeId(), (string) $original->id());
    $owners = [];
    foreach ($candidates as $dependency) {
      if (!$dependency->get('met')->value && $dependency->get('trigger')->value === $this->configuration['dependency_trigger']
        && $dependency->get('action')->value === $this->configuration['dependency_action']
        && $dependency->get('bindings')->first()->entity_uuid === $original->uuid()) {
        $owners[$dependency->uuid()] = $dependency->get('owner')->value;
      }
    }
    if (!$owners) {
      return [];
    }
    $tasks = $this->entities->getStorage('task');
    $ids = $tasks->getQuery()->accessCheck(FALSE)
      ->condition('uuid', array_values(array_unique($owners)), 'IN')
      ->condition('job', $trigger->getJob()->getBaseJobId())->execute();
    $eligible = [];
    foreach ($tasks->loadMultiple($ids) as $task) {
      if (!in_array($task->get('status')->value, ['resolved', 'closed'], TRUE)) {
        $eligible[$task->uuid()] = TRUE;
      }
    }
    $selected = array_keys(array_filter($owners, static fn(string $owner): bool => isset($eligible[$owner])));
    if ($selected) {
      $this->dependencies->retarget($original, $replacement, $selected, TRUE);
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $wrapper = 'retarget-action-' . substr(hash('sha256', implode(':', $form['#parents'] ?? [])), 0, 16);
    $form['#prefix'] = '<div id="' . $wrapper . '">';
    $form['#suffix'] = '</div>';
    $form['scope'] = ['#markup' => '<p>' . $this->t('Move only unmet dependencies on unfinished tasks of this job. Other jobs and already matched dependencies are left alone.') . '</p>'];
    $form['dependency_trigger'] = [
      '#type' => 'select',
      '#title' => $this->t('Dependency event to move'),
      '#options' => $this->events->options(),
      '#default_value' => $this->configuration['dependency_trigger'],
      '#required' => TRUE,
      '#ajax' => ['callback' => [static::class, 'rebuild'], 'wrapper' => $wrapper],
    ];
    $form['dependency_action'] = [
      '#type' => 'select',
      '#title' => $this->t('Dependency effect to move'),
      '#options' => ['activate' => $this->t('Activate'), 'invalidate' => $this->t('Invalidate')],
      '#default_value' => $this->configuration['dependency_action'],
      '#required' => TRUE,
    ];
    $form['context_mapping'] = $this->contextHandler->getContextAssignmentElement($this, $form_state->get('available_contexts') ?? []);
    return $form;
  }

  /**
   * Rebuilds expected mappings when the selected dependency event changes.
   */
  public static function rebuild(array $form, FormStateInterface $form_state): array {
    return NestedArray::getValue($form, array_slice($form_state->getTriggeringElement()['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    foreach (['original', 'replacement'] as $name) {
      if (!$form_state->getValue(['context_mapping', $name])) {
        $form_state->setError($form['context_mapping'][$name], $this->t('Select the @name context.', ['@name' => $name]));
      }
    }
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
