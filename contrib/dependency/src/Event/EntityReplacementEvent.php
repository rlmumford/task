<?php

namespace Drupal\task_dependency\Event;

use Drupal\Core\Entity\EntityInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Explicit source notification; dispatch inside the replacement transaction.
 *
 * No status value or entity save infers this relationship automatically.
 */
class EntityReplacementEvent extends Event {

  /**
   * Constructs a replacement notification with two saved, same-type entities.
   */
  public function __construct(public readonly EntityInterface $original, public readonly EntityInterface $replacement) {
    if ($original->isNew() || $replacement->isNew() || !$original->uuid() || !$replacement->uuid()
      || $original->getEntityTypeId() !== $replacement->getEntityTypeId() || $original->uuid() === $replacement->uuid()) {
      throw new \InvalidArgumentException('Replacement events require different saved entities of the same type.');
    }
  }

}
