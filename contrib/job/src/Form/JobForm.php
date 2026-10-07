<?php

namespace Drupal\task_job\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\task_job\Entity\Job;

/**
 * Base form for editing jobs.
 */
class JobForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $form['#tree'] = TRUE;

    $form['label'] = [
      '#title' => $this->t('Label'),
      '#type' => 'textfield',
      '#default_value' => $this->entity->label(),
      '#description' => $this->t("The job name. This will appear on the list of task templates when the 'Add Task' button is clicked. The task title will be set by the template trigger on the next page."),
      '#required' => TRUE,
      '#size' => 30,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#maxlength' => 128,
      '#machine_name' => [
        'exists' => Job::class . '::load',
        'source' => ['label'],
      ],
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#default_value' => $this->entity->get('description') ?: '',
      '#title' => $this->t('Description'),
      '#description' => $this->t('A description for the job. This will appear on the page when a user is selecting which task template to use. Task documentation should be included in the task description or in the checklist.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);

    if ($this->entity->isNew()) {
      $actions['submit']['#value'] = $this->t('Create & Configure');
      $actions['submit']['#submit'][] = '::submitRedirectEdit';
    }

    return $actions;
  }

  /**
   * Redirect to the edit page.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitRedirectEdit(array $form, FormStateInterface $form_state) {
    $form_state->setRedirect('entity.task_job.edit_form', [
      'task_job' => $this->entity->id(),
    ]);
  }

}
