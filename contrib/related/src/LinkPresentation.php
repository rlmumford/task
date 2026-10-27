<?php

namespace Drupal\task_related;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\task\TaskInterface;

/**
 * Presents the saved context and outcome relationships.
 */
final class LinkPresentation {
  use StringTranslationTrait;

  /**
   * Constructs the presenter.
   */
  public function __construct(private readonly RelatedWork $work, private readonly EntityTypeManagerInterface $entities) {}

  /**
   * Lists referenced records, batching loads by entity type.
   */
  public function records(TaskInterface $task): array {
    if (!$task->access('view')) {
      return [];
    }
    $references = $this->work->references($task);
    $by_type = [];
    foreach ($references as $reference) {
      $by_type[$reference['entity_type']][$reference['entity_id']] = $reference['entity_id'];
    }
    $groups = [];
    foreach ($by_type as $type => $ids) {
      $items = [];
      foreach ($this->entities->getStorage($type)->loadMultiple($ids) as $target) {
        if ($target->access('view') && $this->work->compatible($task, $target)) {
          $label = $target->label() ?? (string) $target->id();
          $items[] = [
            'record' => $target->hasLinkTemplate('canonical') ? Link::fromTextAndUrl($label, $target->toUrl())->toRenderable() : ['#plain_text' => $label],
            'relations' => $this->labels($this->matching($references, $target)),
          ];
        }
      }
      if ($items) {
        $groups[$type] = [
          '#theme' => 'item_list',
          '#title' => $this->entities->getDefinition($type)->getPluralLabel(),
          '#items' => $items,
        ];
      }
    }
    return $groups ? [
      '#type' => 'details',
      '#title' => $this->t('Related records'),
      '#open' => TRUE,
      '#cache' => ['max-age' => 0],
      'groups' => $groups,
    ] : [];
  }

  /**
   * Shows the context or the originating checklist item and outcome name.
   */
  public function relations(TaskInterface $task, EntityInterface $target): array {
    if (!$task->access('view') || !$target->access('view') || !$this->work->compatible($task, $target)) {
      return [];
    }
    return $this->labels($this->matching($this->work->references($task), $target));
  }

  /**
   * Matches the complete entity identity, not just an ID shared by many types.
   */
  private function matching(array $references, EntityInterface $target): array {
    return array_filter($references, static fn(array $reference) => $reference['entity_type'] === $target->getEntityTypeId() && $reference['entity_id'] === (string) $target->id());
  }

  /**
   * Formats saved relationship identities without asserting record creation.
   */
  private function labels(array $references): array {
    $items = [];
    foreach ($references as $reference) {
      $label = $reference['kind'] === 'context'
        ? $this->t('Context: @label (@name)', ['@label' => $reference['label'], '@name' => $reference['name']])
        : $this->t('Outcome: @label (@name) — @item (@item_name)', [
          '@label' => $reference['label'],
          '@name' => $reference['name'],
          '@item' => $reference['item_title'] ?: $reference['item_name'],
          '@item_name' => $reference['item_name'],
        ]);
      $items[(string) $label] = ['#markup' => $label];
    }
    return ['#theme' => 'item_list', '#items' => array_values($items), '#cache' => ['max-age' => 0]];
  }

}
