<?php

namespace Drupal\task;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Task entity form.
 */
class TaskForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $ret = parent::save($form, $form_state);

    if (is_null($form_state->getRedirect())) {
      $form_state->setRedirect('entity.task.canonical', [
        'task' => $this->entity->id(),
      ]);
    }

    return $ret;
  }

}
