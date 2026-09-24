<?php

namespace Drupal\task_job\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\task_job\Entity\Job;
use Drupal\task_job\JobVersionId;

/**
 * Publishes a dirty job working copy into its clean named version.
 */
class JobPublishForm extends JobForm {

  /**
   * {@inheritdoc}
   */
  public function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Publish version');
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $form['confirmation'] = [
      '#markup' => $this->t('Publish this working copy as the clean version @version?', [
        '@version' => $this->entity->getVersion(),
      ]),
      '#weight' => -20,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    if (!$this->entity->isDirty()) {
      $form_state->setErrorByName('confirmation', $this->t('Only dirty job working copies can be published.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $clean_id = JobVersionId::build(
      $this->entity->getBaseJobId(),
      $this->entity->getVersion()
    );
    $storage = $this->entityTypeManager->getStorage('task_job');
    $clean = $storage->load($clean_id);

    if (!$clean instanceof Job) {
      $this->messenger()->addError($this->t('The clean job version no longer exists.'));
      return NULL;
    }

    $values = $this->entity->toArray();
    $metadata_keys = [
      'id', 'uuid', 'version', 'version_of', 'dirty', 'code_revision',
      'last_imported_hash', 'active_hash', 'system_revision',
    ];
    foreach ($metadata_keys as $key) {
      unset($values[$key]);
    }
    foreach ($values as $key => $value) {
      $clean->set($key, $value);
    }
    $clean->set('dirty', FALSE);
    $clean->set('system_revision', $this->entity->getSystemRevision());
    $clean->set('active_hash', Job::definitionHash($clean->toArray()));
    $clean->set('last_imported_hash', $clean->get('active_hash'));
    $clean->save();
    $this->entity->delete();

    $this->messenger()->addStatus($this->t('Version @version has been published.', [
      '@version' => $clean->getVersion(),
    ]));
    $form_state->setRedirect('entity.task_job.edit_form', ['task_job' => $clean->id()]);
  }

}
