<?php

namespace Drupal\task_dependency\Plugin\DependencyTrigger;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\task_dependency\TriggerInterface;

/**
 * Matches entering a configured scalar field value.
 *
 * @DependencyTrigger(
 *   id = "entity.state",
 *   label = @Translation("Entity enters a state"),
 *   deriver = "Drupal\task_dependency\Plugin\Derivative\EntityStateDeriver"
 * )
 */
class EntityState extends PluginBase implements TriggerInterface {

  use ContextAwarePluginTrait;

  /**
   * {@inheritdoc}
   */
  public function validateTarget(EntityInterface $target): void {
    $definitions = $this->getContextDefinitions();
    $definition = reset($definitions);
    if ($definition->getDataType() !== 'entity:' . $target->getEntityTypeId()) {
      throw new \InvalidArgumentException('The target must match the trigger context definition.');
    }
    $field = $this->configuration['field'] ?? '';
    $property = $this->configuration['property'] ?? 'value';
    if (!$target instanceof FieldableEntityInterface || !$target->hasField($field)
      || !isset($target->get($field)->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions()[$property])
      || !is_scalar($this->configuration['value'] ?? NULL)) {
      throw new \InvalidArgumentException('Select an existing scalar field property and a qualifying value.');
    }
  }

  /**
   * Reads the configured scalar property from one entity.
   */
  protected function value(EntityInterface $entity): ?string {
    $value = $entity->get($this->configuration['field'])->first()?->get($this->configuration['property'] ?? 'value')->getValue();
    return is_scalar($value) ? (string) $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function matches(EntityInterface $entity, ?EntityInterface $original): bool {
    try {
      $this->validateTarget($entity);
    }
    catch (\InvalidArgumentException) {
      // A removed field leaves the subscription unmet; it must not prevent
      // unrelated source edits from being saved.
      return FALSE;
    }
    // Creation is not a transition; an unchanged save is not a new occurrence.
    return $original && $this->value($entity) === (string) $this->configuration['value']
      && $this->value($original) !== (string) $this->configuration['value'];
  }

}
