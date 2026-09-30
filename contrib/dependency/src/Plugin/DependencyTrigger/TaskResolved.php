<?php

namespace Drupal\task_dependency\Plugin\DependencyTrigger;

use Drupal\Core\Entity\EntityInterface;

/**
 * Matches terminal task resolution.
 *
 * @DependencyTrigger(
 *   id = "task.resolved",
 *   label = @Translation("Task resolves"),
 *   context_definitions = {
 *     "task" = @ContextDefinition("entity:task", label = @Translation("Resolving task"))
 *   }
 * )
 */
class TaskResolved extends EntityState {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct(['field' => 'status', 'property' => 'value', 'value' => 'resolved'], $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function validateTarget(EntityInterface $target): void {
    if ($target->getEntityTypeId() !== 'task') {
      throw new \InvalidArgumentException('Task resolution requires a task binding.');
    }
    parent::validateTarget($target);
  }

}
