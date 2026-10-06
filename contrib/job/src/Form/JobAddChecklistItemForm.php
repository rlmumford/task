<?php

namespace Drupal\task_job\Form;

use Drupal\checklist\ChecklistContextCollectorInterface;
use Drupal\checklist\Plugin\ChecklistItemHandler\IterativeChecklistItemHandlerInterface;
use Drupal\checklist\Form\ConditionConfigurationForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\task_job\JobConfigurationChecklist;
use Drupal\task_job\JobInterface;
use Drupal\task_job\JobChecklistExpansion;
use Drupal\task_job\ExecutionRule;
use Drupal\task_job\JobExecutionAuthorization;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to add a checklist item.
 */
class JobAddChecklistItemForm extends JobPluginFormBase {

  /**
   * The context collector service.
   *
   * @var \Drupal\checklist\ChecklistContextCollectorInterface
   */
  protected ChecklistContextCollectorInterface $contextCollector;

  /**
   * Embeds condition plugins with configuration-time contexts.
   */
  protected ConditionConfigurationForm $conditions;

  /**
   * Builds the standard execution-context mapping widget.
   *
   * @var \Drupal\Core\Plugin\Context\ContextHandlerInterface
   */
  protected ContextHandlerInterface $executionContexts;

  /**
   * Named template being edited, or NULL for the default checklist.
   */
  protected ?string $checklistTemplate = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = (new static(
      $container->get('task_job.tempstore_repository'),
      $container->get('plugin_form.factory'),
      $container->get('plugin.manager.checklist_item_handler'),
      $container->get('config.factory')
    ))->setChecklistContextCollector($container->get('checklist.context_collector'));
    $instance->executionContexts = $container->get('context.handler');
    $instance->conditions = $container->get('checklist.condition_configuration_form');
    return $instance;
  }

  /**
   * Set the context collector.
   *
   * @param \Drupal\checklist\ChecklistContextCollectorInterface $context_collector
   *   The context collector service.
   *
   * @return $this
   */
  public function setChecklistContextCollector(ChecklistContextCollectorInterface $context_collector) : JobAddChecklistItemForm {
    $this->contextCollector = $context_collector;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_add_checklist_item_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?JobInterface $task_job = NULL,
    $handler = NULL,
    $handler_config = [],
  ) {
    $this->checklistTemplate = $form_state->get('checklist_template') ?? $this->getRequest()->query->get('template');
    $form_state->set('checklist_template', $this->checklistTemplate);
    $form = parent::buildForm(
      $form,
      $form_state,
      $task_job,
      $handler,
      $handler_config
    );

    unset($form['message']);

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#size' => 10,
      '#weight' => -10,
      '#required' => TRUE,
    ];
    if ($default_prefix = $this->config('task_checklist.defaults')->get('ci_name_prefix')) {
      $form['name']['#default_value'] = $default_prefix . str_pad(
          count($form_state->get('job')->getChecklistItems($form_state->get('checklist_template'))) + 1,
          2,
          '0',
          STR_PAD_LEFT
        );
    }

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#weight' => -8,
    ];

    $contexts = $form_state->getTemporaryValue('gathered_contexts') ?? [];
    $configuration = $form_state->get('configured_plugin')->getConfiguration();
    $form['conditions'] = ['#type' => 'details', '#title' => $this->t('Conditions'), '#tree' => TRUE];
    foreach ([
      'applicability' => $this->t('Applicable'),
      'actionability' => $this->t('Actionable'),
      'required' => $this->t('Required'),
    ] as $gate => $label) {
      $element = ['#type' => 'details', '#title' => $label, '#parents' => ['conditions', $gate]];
      $state = SubformState::createForSubform($element, $form, $form_state);
      $form['conditions'][$gate] = $this->conditions->build($element, $state, $configuration['conditions'][$gate] ?? [], $contexts);
    }
    $execution = $form_state->get('execution_configuration') ?? ['mode' => 'self'];
    $plugin = $form_state->get('configured_plugin');
    // Mixed handlers choose their method from live item state and contexts.
    // Authoring has neither: configure iteration capability here and let the
    // execution preparer enforce the automatic method at runtime.
    $supports_iterations = $plugin instanceof IterativeChecklistItemHandlerInterface;
    $consumer = new ExecutionRule($execution);
    $form['execution'] = [
      '#type' => 'details',
      '#title' => $this->t('Execution identity'),
      '#tree' => TRUE,
      '#open' => ($execution['mode'] ?? 'self') === 'context',
      '#access' => $supports_iterations && $this->currentUser()->hasPermission(JobExecutionAuthorization::PERMISSION),
      'mode' => [
        '#type' => 'select',
        '#title' => $this->t('Run automatic work as'),
        '#options' => ['self' => $this->t('Initiating user'), 'context' => $this->t('Context-selected user')],
        '#default_value' => $execution['mode'] ?? 'self',
        '#description' => $this->t('Saving the job authorizes its delegated work. The selected user is fixed for each attempt; account permissions are checked whenever it runs.'),
      ],
    ];
    $form['execution']['context_mapping'] = method_exists($this->executionContexts, 'getContextAssignmentElement')
      ? $this->executionContexts->getContextAssignmentElement($consumer, $contexts)
      : ['executor' => $this->executionContexts->getContextSelectElement($contexts, $consumer->getContextDefinition('executor'), $execution['context_mapping']['executor'] ?? '')];
    $form['execution']['context_mapping']['executor']['#required'] = FALSE;
    $form['execution']['context_mapping']['#states']['visible'] = [':input[name="execution[mode]"]' => ['value' => 'context']];
    $form['contexts'] = ['#type' => 'details', '#title' => $this->t('Available contexts')];
    foreach ($contexts as $name => $context) {
      $form['contexts'][$name] = [
        '#type' => 'item',
        '#title' => $name,
        '#plain_text' => (string) $context->getContextDefinition()->getLabel(),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if (($form_state->getTriggeringElement()['#limit_validation_errors'] ?? NULL) === []) {
      return;
    }
    $execution = $form_state->get('execution_configuration') ?? ['mode' => 'self'];
    if (!empty($form['execution']['#access'])) {
      $execution = $form_state->getValue('execution') ?: ['mode' => 'self'];
      if (($execution['mode'] ?? 'self') === 'context' && empty($execution['context_mapping']['executor'])) {
        $form_state->setError($form['execution']['context_mapping']['executor'], $this->t('Select the execution user context.'));
      }
    }
    $form_state->set('validated_execution', $execution);
    $name = $form_state->getValue('name');
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $name) || (empty($form['name']['#disabled']) && isset($form_state->get('job')->getChecklistItems($form_state->get('checklist_template'))[$name]))) {
      $form_state->setError($form['name'], $this->t('Use a unique machine name starting with a lowercase letter, followed by lowercase letters, digits or underscores.'));
    }
    $gates = [];
    foreach (['applicability', 'actionability', 'required'] as $gate) {
      $element = &$form['conditions'][$gate];
      if ($condition = $this->conditions->configuration($element, SubformState::createForSubform($element, $form, $form_state))) {
        $gates[$gate] = $condition;
      }
    }
    $form_state->set('checklist_conditions', $gates);
    $expands_templates = in_array($form_state->get('configured_plugin')->getPluginId(), [
      'decision',
      'add_checklist_template',
    ], TRUE);
    if (!$form_state->hasAnyErrors() && $expands_templates) {
      $candidate = clone $form_state->get('job');
      $template = $form_state->get('checklist_template');
      $items = $candidate->getChecklistItems($template);
      $plugin = $form_state->get('configured_plugin');
      $items[$name] = [
        'name' => $name,
        'label' => $form_state->getValue('label'),
        'handler' => $plugin->getPluginId(),
        'handler_configuration' => $form['plugin_configuration']['#validated_configuration'] ?? $plugin->getConfiguration(),
      ];
      $candidate->setChecklistItems($items, $template);
      try {
        $candidate->getExpandedChecklistItems();
        // Also validate a template not yet referenced by the main checklist.
        if ($template !== NULL) {
          JobChecklistExpansion::expand($items, $candidate->get('checklist_templates') ?: []);
        }
      }
      catch (\InvalidArgumentException $exception) {
        $form_state->setErrorByName('plugin_configuration', $exception->getMessage());
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    /** @var \Drupal\Component\Plugin\PluginInspectionInterface $plugin */
    $plugin = $form_state->get('plugin');
    $configuration = $plugin->getConfiguration();
    $configuration['conditions'] = $form_state->get('checklist_conditions');
    $plugin->setConfiguration($configuration);
    $job = $form_state->get('job');
    $checklist_items = $job->getChecklistItems($form_state->get('checklist_template'));
    $checklist_items[$form_state->getValue('name')] = [
      'name' => $form_state->getValue('name'),
      'label' => $form_state->getValue('label'),
      'handler' => $plugin->getPluginId(),
      'handler_configuration' => $plugin->getConfiguration(),
      'execution' => $form_state->get('validated_execution') ?? ['mode' => 'self'],
    ];
    $job->setChecklistItems($checklist_items, $form_state->get('checklist_template'));

    $this->tempstoreRepository->set($job);
  }

  /**
   * Gather the contexts available for this plugin.
   *
   * @param \Drupal\task_job\JobInterface $task_job
   *   The job.
   *
   * @return \Drupal\Core\Plugin\Context\ContextInterface[]
   *   A list of contexts available to the plugin.
   */
  protected function gatherContexts(JobInterface $task_job) {
    return $this->contextCollector->collectConfigContexts(JobConfigurationChecklist::createFromJob($task_job, NULL, $this->checklistTemplate));
  }

}
