<?php

namespace Drupal\task_job\Plugin\Derivative;

use Drupal\entity_template\Plugin\Derivative\ConfigTemplateBuilderDeriver;

/**
 * Deriver for builder plugins.
 */
class JobEntityTemplateBuilderDeriver extends ConfigTemplateBuilderDeriver {

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    /** @var \Drupal\task_job\JobInterface $job */
    foreach ($this->entityTypeManager->getStorage('task_job')->loadMultiple() as $job) {
      $context_definitions = [];
      // @todo Contexts
      $this->derivatives[$job->id()] = [
        'task_job' => $job->id(),
        'label' => $job->label(),
        'context_definitions' => $context_definitions,
      ] + $base_plugin_definition;
    }

    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

}
