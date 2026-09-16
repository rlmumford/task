<?php

namespace Drupal\task_job\Plugin\JobTrigger;

/**
 * Plugin for when a trigger is missing.
 *
 * @JobTrigger(
 *   id = "missing",
 *   label = @Translation("Missing/Broken"),
 *   description = @Translation("The trigger is missing or broken."),
 * )
 *
 * @package Drupal\task_job\Plugin\JobTrigger
 */
class Missing extends JobTriggerBase {

  /**
   * The intended plugin id.
   *
   * @var string
   */
  protected $intendedPluginId;

  /**
   * Set the intended plugin id.
   *
   * @param string $id
   *   The intended plugin id.
   *
   * @return $this
   */
  public function setIntendedPluginId(string $id) : Missing {
    $this->intendedPluginId = $id;
    return $this;
  }

  /**
   * Get the intended plugin id.
   *
   * @return string
   *   The intended plugin id.
   */
  public function getIntendedPluginId() : string {
    return $this->intendedPluginId ?? 'missing';
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultKey(): string {
    return 'missing';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->t('Broken/Missing Trigger');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The configured trigger (@intended_id) is broken or missing.', ['@intended_id' => $this->intendedPluginId]);
  }

}
