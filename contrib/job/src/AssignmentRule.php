<?php

namespace Drupal\task_job;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\Core\Plugin\PluginBase;

/**
 * Supplies the single assignee input to Drupal's context mapping system.
 */
class AssignmentRule extends PluginBase implements ContextAwarePluginInterface {
  use ContextAwarePluginTrait;

  /**
   * Constructs the context consumer without separate plugin discovery.
   */
  public function __construct(array $configuration) {
    parent::__construct($configuration, 'task_job_assignment', [
      'context_definitions' => [
        'assignee' => ContextDefinition::create('entity:user')
          ->setLabel($this->t('Assignee')),
      ],
    ]);
  }

}
