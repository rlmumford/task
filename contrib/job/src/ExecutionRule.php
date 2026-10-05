<?php

namespace Drupal\task_job;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\Core\Plugin\PluginBase;

/**
 * Selects an executor through the standard filtered context handler.
 */
class ExecutionRule extends PluginBase implements ContextAwarePluginInterface {

  use ContextAwarePluginTrait;

  public function __construct(array $configuration) {
    parent::__construct($configuration, 'task_job_execution', [
      'context_definitions' => [
        'executor' => ContextDefinition::create('entity:user')->setLabel($this->t('Execution user')),
      ],
    ]);
  }

}
