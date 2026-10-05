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
    $form['work'] = [
      '#type' => 'details',
      '#title' => $this->t('Add work'),
      '#open' => FALSE,
    ];
    $form['work']['template'] = [
      '#type' => 'select',
      '#title' => $this->t('Checklist template'),
      '#options' => array_map(static fn(array $choice) => $choice['label'], $choices['templates']),
      '#empty_option' => $this->t('- Select work -'),
      '#required' => TRUE,
      '#description' => $this->t('Adds a separate set of items to this task.'),
    ];
    $form['work']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add selected work'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $task = $form_state->getBuildInfo()['args'][0];
    try {
      $this->manager->add($task, $form_state->getValue('template'), $form_state->getValue('request_id'));
      $this->messenger()->addStatus($this->t('The checklist work has been added.'));
      $form_state->setRedirectUrl($task->toUrl());
    }
    catch (HttpException | \InvalidArgumentException $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->setRebuild();
    }
  }

}
