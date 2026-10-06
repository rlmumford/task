<?php

namespace Drupal\task_job\Form;

use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\task_job\JobInterface;
use Drupal\task_job\TaskJobTempstoreRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits one template input in the shared job draft.
 */
class TemplateContextForm extends FormBase {

  use AjaxFormHelperTrait;

  public function __construct(protected TaskJobTempstoreRepository $drafts, protected TypedDataManagerInterface $typedData) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('task_job.tempstore_repository'), $container->get('typed_data_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'task_job_template_context_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?JobInterface $task_job = NULL, ?string $template = NULL, ?string $context_name = NULL) {
    $job = $this->drafts->get($task_job);
    $templates = $job->get('checklist_templates') ?: [];
    if (!isset($templates[$template]) || ($context_name !== NULL && !isset($templates[$template]['context'][$context_name]))) {
      throw new NotFoundHttpException();
    }
    $definition = $templates[$template]['context'][$context_name] ?? [];
    $form_state->set('job', $job)->set('template', $template)->set('context_name', $context_name);
    $form['#id'] = $this->getFormId();
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Machine name'),
      '#required' => TRUE,
      '#default_value' => $context_name ?? '',
      '#disabled' => $context_name !== NULL,
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Input label'),
      '#required' => TRUE,
      '#default_value' => $definition['label'] ?? '',
    ];
    $options = array_map(static fn(array $type) => $type['label'], $this->typedData->getDefinitions());
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Data type'),
      '#options' => $options,
      '#default_value' => $definition['type'] ?? 'string',
    ];
    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $definition['description'] ?? '',
    ];
    $form['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required'),
      '#default_value' => $definition['required'] ?? TRUE,
    ];
    $form['multiple'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow multiple values'),
      '#default_value' => $definition['multiple'] ?? FALSE,
    ];
    if ($context_name !== NULL) {
      $form['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove input'),
        '#description' => $this->t('Also removes this input’s addition button mappings. Update any items that consume it before saving the job.'),
      ];
    }
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $context_name === NULL ? $this->t('Add input') : $this->t('Update input'),
    ];
    if ($this->isAjax()) {
      $form['actions']['submit']['#ajax']['callback'] = '::ajaxSubmit';
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $name = trim($form_state->getValue('name'));
    $definitions = $form_state->get('job')->get('checklist_templates')[$form_state->get('template')]['context'] ?? [];
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $name) || ($form_state->get('context_name') === NULL && isset($definitions[$name]))) {
      $form_state->setError($form['name'], $this->t('Use a unique machine name starting with a lowercase letter, followed by lowercase letters, digits or underscores.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Merge into the latest draft rather than an older dialog's job snapshot.
    $job = $this->drafts->get($form_state->get('job'));
    $templates = $job->get('checklist_templates');
    $template = $form_state->get('template');
    $name = $form_state->get('context_name') ?? trim($form_state->getValue('name'));
    if ($form_state->getValue('remove')) {
      unset($templates[$template]['context'][$name]);
      foreach (array_keys($templates[$template]['exposures'] ?? []) as $button) {
        unset($templates[$template]['exposures'][$button]['context_mapping']['template_context:' . $name]);
      }
    }
    else {
      $templates[$template]['context'][$name] = [
        'type' => $form_state->getValue('type'),
        'label' => trim($form_state->getValue('label')),
        'description' => $form_state->getValue('description'),
        'required' => (bool) $form_state->getValue('required'),
        'multiple' => (bool) $form_state->getValue('multiple'),
      ];
    }
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
