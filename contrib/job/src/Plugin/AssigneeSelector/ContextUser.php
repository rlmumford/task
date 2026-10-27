<?php

namespace Drupal\task_job\Plugin\AssigneeSelector;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\task\TaskInterface;
use Drupal\task_job\AssigneeSelectorBase;
use Drupal\user\UserInterface;

/**
 * Preserves ordinary single-user context mappings for existing jobs.
 *
 * @AssigneeSelector(id = "context_user", label = @Translation("User from context"))
 */
class ContextUser extends AssigneeSelectorBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    $plugin_definition['context_definitions']['assignee'] = ContextDefinition::create('entity:user')->setLabel($this->t('Assignee'));
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function select(TaskInterface $task): ?UserInterface {
    $user = $this->getContextValue('assignee');
    return $user instanceof UserInterface ? $user : NULL;
  }

}
