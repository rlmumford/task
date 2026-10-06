<?php

namespace Drupal\task_job_additions\Form;

use Drupal\checklist\ChecklistContextCollectorInterface;
use Drupal\checklist\ChecklistContextMapping;
use Drupal\checklist\Form\ConditionConfigurationForm;
use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\task_job\JobConfigurationChecklist;
use Drupal\task_job\JobInterface;
use Drupal\task_job\TaskJobTempstoreRepository;
use Drupal\task_job_additions\AdditionDefinitions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Configures one addition button in the shared job draft.
 */
class ExposureForm extends FormBase {

  use AjaxFormHelperTrait;

  public function __construct(protected TaskJobTempstoreRepository $drafts, protected ChecklistContextCollectorInterface $collector, protected ContextHandlerInterface $contextHandler, protected ConditionConfigurationForm $conditions) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('task_job.tempstore_repository'), $container->get('checklist.context_collector'), $container->get('context.handler'), $container->get('checklist.condition_configuration_form'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'task_job_addition_exposure_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?JobInterface $task_job = NULL, ?string $template = NULL, ?string $exposure = NULL) {
    $job = $this->drafts->get($task_job);
    $templates = $job->get('checklist_templates') ?: [];
    $buttons = AdditionDefinitions::exposures($templates[$template] ?? []);
    if (!isset($templates[$template]) || ($exposure !== NULL && !isset($buttons[$exposure]))) {
      throw new NotFoundHttpException();
    }
    $configuration = $buttons[$exposure] ?? [];
    $form_state->set('job', $job)->set('template', $template)->set('exposure', $exposure);
    $form['#id'] = $this->getFormId();
    $form['#tree'] = TRUE;
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button machine name'),
      '#required' => TRUE,
      '#default_value' => $exposure ?? '',
      '#disabled' => $exposure !== NULL,
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Addition label'),
      '#description' => $this->t('Leave blank to use the template label.'),
      '#default_value' => $configuration['label'] ?? '',
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose template as an addition'),
      '#description' => $this->t('Disable to hide this button without changing existing work.'),
      '#default_value' => !empty($configuration['enabled']),
    ];
    $contexts = $this->collector->collectConfigContexts(JobConfigurationChecklist::createFromJob($job));
    $definitions = ChecklistContextMapping::definitions($templates[$template]['context'] ?? []);
    foreach ($definitions as $definition) {
      $definition->setRequired(FALSE);
    }
    $mapping = ChecklistContextMapping::fromDefinitions($definitions, $configuration['context_mapping'] ?? []);
    $form['context_mapping'] = ['#tree' => TRUE];
    if (method_exists($this->contextHandler, 'getContextAssignmentElement')) {
      $form['context_mapping'] = $this->contextHandler->getContextAssignmentElement($mapping, $contexts);
    }
    else {
      foreach ($definitions as $name => $definition) {
        $matches = $this->contextHandler->getMatchingContexts($contexts, $definition);
        $form['context_mapping'][$name] = [
          '#type' => 'select',
          '#title' => $definition->getLabel(),
          '#options' => array_map(static fn($context) => $context->getContextDefinition()->getLabel(), $matches),
          '#empty_option' => $this->t('- Not mapped -'),
          '#default_value' => $configuration['context_mapping'][$name] ?? '',
        ];
      }
    }
    $form['context_mapping']['#type'] = 'details';
    $form['context_mapping']['#title'] = $this->t('Template input mapping');
    $form['context_mapping']['#open'] = TRUE;
    $form['context_mapping']['#description'] = $this->t('Map the template inputs from task contexts, outcomes or global providers. Missing required inputs prevent the items from running.');
    $form['condition'] = $this->conditions->build([
      '#type' => 'details',
      '#title' => $this->t('Addition availability'),
      '#open' => TRUE,
      '#parents' => ['condition'],
    ], $form_state, $configuration['condition'] ?? [], $contexts);
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $exposure === NULL ? $this->t('Add button') : $this->t('Update button'),
    ];
    if ($this->isAjax()) {
      $form['actions']['submit']['#ajax']['callback'] = '::ajaxSubmit';
      $this->ajaxRebuildControls($form);
    }
    return $form;
  }

  /**
   * Keeps nested condition selection inside the same off-canvas panel.
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
   * Rebuilds condition options without committing partial configuration.
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
    $name = trim($form_state->getValue('name'));
    $template = $form_state->get('job')->get('checklist_templates')[$form_state->get('template')];
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $name) || ($form_state->get('exposure') === NULL && isset(AdditionDefinitions::exposures($template)[$name]))) {
      $form_state->setError($form['name'], $this->t('Use a unique lowercase machine name for the button.'));
    }
    $condition = $this->conditions->configuration($form['condition'], SubformState::createForSubform($form['condition'], $form, $form_state));
    $form_state->set('condition_configuration', $condition);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $job = $this->drafts->get($form_state->get('job'));
    $templates = $job->get('checklist_templates');
    $template = $form_state->get('template');
    $name = $form_state->get('exposure') ?? trim($form_state->getValue('name'));
    $buttons = AdditionDefinitions::exposures($templates[$template]);
    $buttons[$name] = [
      'label' => trim($form_state->getValue('label')),
      'enabled' => (bool) $form_state->getValue('enabled'),
      'context_mapping' => array_intersect_key(
        array_filter($form_state->getValue('context_mapping', []), static fn($value) => $value !== ''),
        ChecklistContextMapping::definitions($templates[$template]['context'] ?? []),
      ),
    ];
    if ($condition = $form_state->get('condition_configuration')) {
      $buttons[$name]['condition'] = $condition;
    }
    unset($templates[$template]['allow_addition'], $templates[$template]['addition_label'], $templates[$template]['addition_condition'], $templates[$template]['addition_context_mapping']);
    $templates[$template]['exposures'] = $buttons;
    $job->set('checklist_templates', $templates);
    $this->drafts->set($job, 'templates', $template);
    $form_state->set('job', $job);
    $form_state->setRedirectUrl($this->drafts->getEditUrl($job));
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    return (new AjaxResponse())->addCommand(new RedirectCommand($this->drafts->getEditUrl($form_state->get('job'))->toString()));
  }

}
