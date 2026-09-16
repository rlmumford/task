<?php

namespace Drupal\task_job\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form to enable jobs.
 *
 * @package Drupal\task_job\Form
 */
class JobDisableForm extends EntityConfirmFormBase {

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
    return $this->t('Disabling a job will stop all triggers from firing in future. Tasks that have already been triggered will be unchanged.');
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to disable @job?', ['@job' => $this->entity->label()]);
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

    $this->entity->disable()->save();
    $form_state->setRedirectUrl($this->entity->toUrl('edit-form'));
  }

}
