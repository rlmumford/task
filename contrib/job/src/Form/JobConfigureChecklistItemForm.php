<?php

namespace Drupal\task_job\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\task_job\JobInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form to configure a checklist item.
 */
class JobConfigureChecklistItemForm extends JobAddChecklistItemForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_configure_checklist_item_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?JobInterface $task_job = NULL,
    $name = NULL,
    $conf = []
  ) {
    // Get the Job from tempstore if available.
    $job = $task_job;
    if ($this->tempstoreRepository->has($job)) {
      $job = $this->tempstoreRepository->get($job);
    }
    $form_state->set('job', $job);

    $checklist_items = $job->get('default_checklist');
    if (!isset($checklist_items[$name])) {
      throw new NotFoundHttpException();
    }
    $item = $checklist_items[$name];

    $form = parent::buildForm(
      $form,
      $form_state,
      $task_job,
      $item['handler'],
      $item['handler_configuration']
    );

    $form['name']['#default_value'] = $item['name'];
    $form['name']['#disabled'] = TRUE;

    $form['label']['#default_value'] = $item['label'];

    $form['actions']['submit']['#value'] = $this->t('Update');

    return $form;
  }

}
