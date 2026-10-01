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
use Drupal\task_job\AssignmentRule;
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
    $configured = $job->get('assignment_rules') ?: [];
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
    $consumer = new AssignmentRule($configuration);
    $form['context_mapping'] = method_exists($this->contextHandler, 'getContextAssignmentElement')
      ? $this->contextHandler->getContextAssignmentElement($consumer, $contexts)
      : ['assignee' => $this->contextHandler->getContextSelectElement($contexts, $consumer->getContextDefinition('assignee'), $configuration['context_mapping']['assignee'] ?? '')];
    $form['context_mapping']['assignee']['#description'] = $this->t('Select an account from the task, its job contexts, or a global context provider. A matched rule with a missing or blocked account leaves the task unassigned.');
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
    if (isset($element['#checklist_configuration_add']) || isset($element['#submit']) && ($element['#limit_validation_errors'] ?? NULL) === []) {
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
    if (($form_state->getTriggeringElement()['#limit_validation_errors'] ?? NULL) === []) {
      return;
    }
    $condition = $this->conditions->configuration($form['condition'], SubformState::createForSubform($form['condition'], $form, $form_state));
    $form_state->set('condition_configuration', $condition);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $job = $form_state->get('job');
    $rules = $job->get('assignment_rules') ?: [];
    $key = $form_state->get('rule') ?? $this->uuid->generate();
    $rules[$key] = [
      'label' => $form_state->getValue('label'),
      'context_mapping' => $form_state->getValue('context_mapping'),
    ];
    if ($condition = $form_state->get('condition_configuration')) {
      $rules[$key]['condition'] = $condition;
    }
    $job->set('assignment_rules', $rules);
    $this->drafts->set($job, 'assignment');
    $form_state->setRedirectUrl($this->drafts->getEditUrl($job));
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    return (new AjaxResponse())->addCommand(new RedirectCommand($this->drafts->getEditUrl($form_state->get('job'))->toString()));
  }

}
