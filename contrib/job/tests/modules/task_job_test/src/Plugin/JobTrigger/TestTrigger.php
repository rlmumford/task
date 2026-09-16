<?php

namespace Drupal\task_job_test\Plugin\JobTrigger;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerBase;

/**
 * Test trigger plugin.
 *
 * @JobTrigger(
 *   id = "test_trigger",
 *   label = @Translation("Test Trigger"),
 *   category = @Translation("Tests"),
 *   description = @Translation("Trigger used for testing"),
 * )
 *
 * @package Drupal\task_job_test\Plugin\JobTrigger
 */
class TestTrigger extends JobTriggerBase {

  /**
   * {@inheritdoc}
   */
  protected function getDefaultKey(): string {
    return 'test_trigger';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return new TranslatableMarkup("Test Trigger");
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return new TranslatableMarkup("Test Trigger");
  }

}
