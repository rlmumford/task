<?php

namespace Drupal\task_dependency_job\Plugin\JobTrigger;

use Drupal\task_job\Plugin\JobTrigger\JobTriggerBase;

/**
 * Responds to an explicit replacement event supplied by the source workflow.
 *
 * @JobTrigger(
 *   id = "entity.replaced",
 *   label = @Translation("Entity replaced"),
 *   category = @Translation("Workflow events"),
 *   deriver = "Drupal\task_dependency_job\Plugin\Derivative\ReplacementTriggerDeriver"
 * )
 */
class EntityReplaced extends JobTriggerBase {

  /**
   * {@inheritdoc}
   */
  public function getDefaultKey(): string {
    return $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Run the configured action when replacement work is explicitly reported.');
  }

}
