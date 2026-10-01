<?php

namespace Drupal\task_job;

use Drupal\checklist\Checklist;
use Drupal\checklist\Entity\ChecklistItemInterface;

/**
 * Resolves unfinished work against the task's current named job version.
 */
class JobChecklist extends Checklist {

  /**
   * Whether definitions have been resolved in this request.
   */
  protected bool $definitionsCurrent = FALSE;

  /**
   * Re-resolves job definitions after restoring a working checklist snapshot.
   */
  public function __wakeup(): void {
    $this->definitionsCurrent = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getItems(): array {
    // HTML action forms restore a serialized checklist from tempstore. Keep
    // its unsaved working items, but resolve their configuration afresh too.
    if (!$this->definitionsCurrent && $this->items !== NULL) {
      $this->items = $this->applyItemDefinitions($this->items);
    }
    $items = parent::getItems();
    $this->definitionsCurrent = TRUE;
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  protected function applyItemDefinition(ChecklistItemInterface $stored, ChecklistItemInterface $definition): void {
    parent::applyItemDefinition($stored, $definition);
    // Completed configuration is part of the receipt, including resolved
    // placeholders and outcome definitions. Do not rewrite completed work.
    if ($stored->isComplete()) {
      return;
    }
    if ($stored->get('handler')->id !== $definition->get('handler')->id && !$stored->get('state')->isEmpty()) {
      throw new \DomainException(sprintf('Checklist item "%s" has working state for a different handler. Restore the previous handler or use a new item name.', $stored->getName()));
    }
    $stored->set('title', $definition->get('title')->getValue());
    // Replace the whole field value, including its computed plugin cache, so
    // configuration and the handler instance cannot disagree after a reload.
    $stored->set('handler', $definition->get('handler')->getValue());
    $stored->get('handler')->first()->get('plugin')->setValue(NULL, FALSE);
  }

}
