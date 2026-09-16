<?php

namespace Drupal\task_job\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Checklist completion form for job checklists.
 *
 * @package Drupal\task_job\PluginForm
 */
class JobChecklistCompleteForm extends PluginFormBase {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['actions']['complete']['#value'] = $this->t('Resolve Task');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Do Nothing.
  }

}
