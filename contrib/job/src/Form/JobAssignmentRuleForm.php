<?php

namespace Drupal\task_job\Form;

use Drupal\checklist\Form\ConditionConfigurationForm;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\task_job\AssignmentRules;
use Drupal\task_job\JobInterface;
use Drupal\task_job\TaskJobTempstoreRepository;
use Drupal\typed_data_plus\Plugin\Context\ContextHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits one assignment in the shared job draft.
 */
class JobAssignmentRuleForm extends FormBase {
  use AjaxFormHelperTrait;

  /**
   * Constructs the rule editor.
   */
  public function __construct(protected TaskJobTempstoreRepository $drafts, protected AssignmentRules $rules, protected ConditionConfigurationForm $conditions, protected ContextHandler $contextHandler, protected UuidInterface $uuid) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('task_job.tempstore_repository'), $container->get('task_job.assignment_rules'), $container->get('checklist.condition_configuration_form'), $container->get('context.handler'), $container->get('uuid'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'task_job_assignment_rule_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?JobInterface $task_job = NULL, ?string $rule = NULL) {
    $job = $this->drafts->get($task_job);
    $configured = $job->getDefinitionValue('assignment_rules') ?: [];
    if ($rule !== NULL && !isset($configured[$rule])) {
      throw new NotFoundHttpException();
    }
    $configuration = $configured[$rule] ?? [];
    $form_state->set('job', $job)->set('rule', $rule);
    $form['#tree'] = TRUE;
    $form['#id'] = $this->getFormId();
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rule label'),
      '#required' => TRUE,
      '#default_value' => $configuration['label'] ?? '',
    ];
    $contexts = $this->rules->contexts($job);
    $selector = $form_state->getUserInput()['selector'] ?? $form_state->getValue('selector') ?? $configuration['selector'] ?? 'context_user';
    $form['selector'] = [
      '#type' => 'select',
      '#title' => $this->t('Select assignee using'),
      '#options' => $this->rules->selectorOptions(),
      '#default_value' => $selector,
    ];
    $form['change_selector'] = [
      '#type' => 'submit',
      '#value' => $this->t('Configure selection'),
      '#submit' => ['::changeSelector'],
      '#limit_validation_errors' => [['selector']],
    ];
    if ($selector !== ($configuration['selector'] ?? 'context_user')) {
      unset($configuration['selector_settings'], $configuration['context_mapping']);
    }
    $consumer = $this->rules->selector(['selector' => $selector] + $configuration);
    $form_state->set('selector_plugin', $consumer);
    $form['selector_settings'] = $consumer->buildConfigurationForm([
      '#type' => 'container',
      '#parents' => ['selector_settings'],
    ], $form_state);
    if (method_exists($this->contextHandler, 'getContextAssignmentElement')) {
      $form['context_mapping'] = $this->contextHandler->getContextAssignmentElement($consumer, $contexts);
    }
    else {
      $form['context_mapping'] = [];
      foreach ($consumer->getContextDefinitions() as $name => $definition) {
        $form['context_mapping'][$name] = $this->contextHandler->getContextSelectElement($contexts, $definition, $configuration['context_mapping'][$name] ?? '');
      }
    }
    $form['context_mapping']['#description'] = $this->t('A matched rule with no eligible account leaves the task unassigned.');
    $form['condition'] = $this->conditions->build([
      '#type' => 'fieldset',
      '#title' => $this->t('When to assign'),
      '#parents' => ['condition'],
    ], $form_state, $configuration['condition'] ?? [], $contexts);
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $rule === NULL ? $this->t('Add rule') : $this->t('Update rule'),
    ];
    if ($this->isAjax()) {
      $form['actions']['submit']['#ajax']['callback'] = '::ajaxSubmit';
      $this->ajaxRebuildControls($form);
    }
    return $form;
  }

  /**
   * Keeps condition-selection and operand rebuilds inside the current dialog.
   */
  protected function ajaxRebuildControls(array &$element): void {
    if (isset($element['#checklist_configuration_add']) || isset($element['#submit']) && is_array($element['#limit_validation_errors'] ?? NULL)) {
      $element['#ajax']['callback'] = '::rebuildDialog';
    }
    foreach ($element as $key => &$child) {
      if (is_array($child) && !str_starts_with((string) $key, '#')) {
        $this->ajaxRebuildControls($child);
      }
    }
    unset($child);
  }

  /**
   * Returns a rebuilt form without persisting partially configured conditions.
   */
  public function rebuildDialog(array &$form, FormStateInterface $form_state): AjaxResponse {
    return (new AjaxResponse())->addCommand(new ReplaceCommand('#' . $form['#id'], $form));
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (is_array($form_state->getTriggeringElement()['#limit_validation_errors'] ?? NULL)) {
      return;
    }
    $form_state->get('selector_plugin')->validateConfigurationForm($form['selector_settings'], SubformState::createForSubform($form['selector_settings'], $form, $form_state));
    $condition = $this->conditions->configuration($form['condition'], SubformState::createForSubform($form['condition'], $form, $form_state));
    $form_state->set('condition_configuration', $condition);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $job = $form_state->get('job');
    $rules = $job->getDefinitionValue('assignment_rules') ?: [];
    $key = $form_state->get('rule') ?? $this->uuid->generate();
    $plugin = $form_state->get('selector_plugin');
    $plugin->submitConfigurationForm($form['selector_settings'], SubformState::createForSubform($form['selector_settings'], $form, $form_state));
    $settings = $plugin->getConfiguration();
    unset($settings['context_mapping']);
    $rules[$key] = [
      'selector' => $plugin->getPluginId(),
      'selector_settings' => $settings,
      'label' => $form_state->getValue('label'),
      'context_mapping' => $form_state->getValue('context_mapping'),
    ];
    if ($condition = $form_state->get('condition_configuration')) {
      $rules[$key]['condition'] = $condition;
    }
    $job->setDefinitionValue('assignment_rules', $rules);
    $this->drafts->set($job, 'assignment');
    $form_state->setRedirectUrl($this->drafts->getEditUrl($job));
  }

  /**
   * Rebuilds the selector inputs without saving a partially configured rule.
   */
  public function changeSelector(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    return (new AjaxResponse())->addCommand(new RedirectCommand($this->drafts->getEditUrl($form_state->get('job'))->toString()));
  }

}
