<?php

namespace Drupal\task_dependency;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;

/**
 * Pure matching contract shared by event subscriptions and job adapters.
 */
interface TriggerInterface extends ContextAwarePluginInterface {

  /**
   * Validates the target and configuration, throwing on unsupported input.
   */
  public function validateTarget(EntityInterface $target): void;

  /**
   * Matches a transition at save time, never against delayed worker state.
   */
  public function matches(EntityInterface $entity, ?EntityInterface $original): bool;

}
