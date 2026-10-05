<?php

namespace Drupal\task_job_additions\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\task\Entity\Task;
use Drupal\task_job_additions\AdditionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Adds an authored template in the task's checklist action pane.
 */
class AddWorkForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(protected AdditionManager $manager, protected UuidInterface $uuid) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('task_job_additions.manager'), $container->get('uuid'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'task_job_add_work';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Task $task = NULL): array {
    $choices = $this->manager->discover($task);
    if (!$form_state->has('addition_request')) {
      $form_state->set('addition_request', $this->uuid->generate());
    }
    // Preserve the request identity across repeated POSTs, even after Drupal
    // discards the successfully submitted form cache. This is an idempotency
    // key, not authority: the manager rechecks its task, template and actor.
    $form['request_id'] = [
      '#type' => 'hidden',
      '#default_value' => $form_state->get('addition_request'),
    ];
    $form['#prefix'] = '<div id="task-addition-form-' . $task->uuid() . '">';
    $form['#suffix'] = '</div>';
    $ajax = ['callback' => '::refresh', 'wrapper' => 'task-addition-form-' . $task->uuid()];
    if ($form_state->get('choose_addition')) {
      $form['work'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['checklist-addition-chooser']],
      ];
      $form['work']['template'] = [
        '#type' => 'select',
        '#title' => $this->t('What else would you like to do?'),
        '#options' => array_map(static fn(array $choice) => $choice['label'], $choices['templates']),
        '#empty_option' => $this->t('- Select work -'),
        '#required' => TRUE,
      ];
      $form['work']['actions'] = ['#type' => 'actions'];
      $form['work']['actions']['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add selected work'),
      ];
      $form['work']['actions']['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->t('Cancel'),
        '#submit' => ['::choose'],
        '#limit_validation_errors' => [],
        '#ajax' => $ajax,
        '#choose_addition' => FALSE,
      ];
    }
    else {
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['label'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Other Actions'),
        '#dropbutton' => 'additions',
      ];
      $overflow = count($choices['templates']) > 5;
      foreach (array_slice($choices['templates'], 0, $overflow ? 4 : 5, TRUE) as $name => $choice) {
        $form['actions']['addition_' . $name] = [
          '#type' => 'submit',
          '#name' => 'addition_' . $name,
          '#value' => $choice['label'],
          '#addition_template' => $name,
          '#dropbutton' => 'additions',
        ];
      }
      if ($overflow) {
        $form['actions']['more'] = [
          '#type' => 'submit',
          '#name' => 'choose_addition',
          '#value' => $this->t('Do something else'),
          '#dropbutton' => 'additions',
          '#submit' => ['::choose'],
          '#limit_validation_errors' => [],
          '#ajax' => $ajax,
          '#choose_addition' => TRUE,
        ];
      }
    }
    return $form;
  }

  /**
   * Opens or cancels the inline chooser without creating checklist work.
   */
  public function choose(array &$form, FormStateInterface $form_state): void {
    $form_state->set('choose_addition', $form_state->getTriggeringElement()['#choose_addition']);
    $form_state->setRebuild();
  }

  /**
   * Returns the rebuilt chooser in the checklist action area.
   */
  public function refresh(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $task = $form_state->getBuildInfo()['args'][0];
    try {
      $this->manager->add($task, $form_state->getTriggeringElement()['#addition_template'] ?? $form_state->getValue('template'), $form_state->getValue('request_id'));
      $this->messenger()->addStatus($this->t('The checklist work has been added.'));
      $form_state->setRedirectUrl($task->toUrl());
    }
    catch (HttpException | \InvalidArgumentException $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->setRebuild();
    }
  }

}
