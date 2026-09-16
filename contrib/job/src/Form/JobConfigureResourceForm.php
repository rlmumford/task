<?php

namespace Drupal\task_job\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\task_job\JobInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form to configure resources.
 *
 * @package Drupal\task_job\Form
 */
class JobConfigureResourceForm extends JobAddResourceForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_configure_resource_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?JobInterface $task_job = NULL,
    $uuid = NULL,
    $plugin_configuration = []
  ) {
    // Get the Job from tempstore if available.
    $job = $task_job;
    if ($this->tempstoreRepository->has($job)) {
      $job = $this->tempstoreRepository->get($job);
    }
    $form_state->set('job', $job);

    $resources = $job->getResourcesCollection()->getConfiguration();
    if (!isset($resources[$uuid])) {
      throw new NotFoundHttpException();
    }
    $form_state->set('resource_uuid', $uuid);

    return parent::buildForm(
      $form,
      $form_state,
      $task_job,
      $resources[$uuid]['id'],
      $resources[$uuid]
    );
  }

}
