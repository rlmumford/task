<?php

namespace Drupal\task_job\Form;

use Drupal\checklist\ChecklistContextCollectorInterface;
use Drupal\checklist\Form\ConditionConfigurationForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\task_job\JobConfigurationChecklist;
use Drupal\task_job\JobInterface;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = (new static(
      $container->get('task_job.tempstore_repository'),
      $container->get('plugin_form.factory'),
      $container->get('plugin.manager.checklist_item_handler'),
      $container->get('config.factory')
    ))->setChecklistContextCollector($container->get('checklist.context_collector'));
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
          count($form_state->get('job')->getChecklistItems()) + 1,
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
    $name = $form_state->getValue('name');
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $name) || (empty($form['name']['#disabled']) && isset($form_state->get('job')->getChecklistItems()[$name]))) {
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
    $checklist_items = $job->get('default_checklist');
    $checklist_items[$form_state->getValue('name')] = [
      'name' => $form_state->getValue('name'),
      'label' => $form_state->getValue('label'),
      'handler' => $plugin->getPluginId(),
      'handler_configuration' => $plugin->getConfiguration(),
    ];
    $job->set('default_checklist', $checklist_items);

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
    return $this->contextCollector->collectConfigContexts(JobConfigurationChecklist::createFromJob($task_job));
  }

}
