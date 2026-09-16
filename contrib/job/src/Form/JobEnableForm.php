<?php

namespace Drupal\task_job\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form to enable jobs.
 *
 * @package Drupal\task_job\Form
 */
class JobEnableForm extends EntityConfirmFormBase {

  /**
   * The job.
   *
   * @var \Drupal\task_job\JobInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Enabling a job will allow all triggers to fire in future.');
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to enable @job?', ['@job' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->entity->toUrl('edit-form');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->entity->enable()->save();
    $form_state->setRedirectUrl($this->entity->toUrl('edit-form'));
  }

}
