<?php

namespace Drupal\task_checklist_sample\Plugin\ChecklistType;

use Drupal\checklist\Plugin\ChecklistType\ChecklistTypeBase;

/**
 * Class TestSimpleChecklist.
 *
 * @ChecklistType(
 *   id = "sample_simple_task",
 *   label = "Test Simple Task Checklist",
 *   entity_type = "task",
 * )
 *
 * @package Drupal\task_checklist_test\Plugin\ChecklistType
 */
class SampleSimpleChecklist extends ChecklistTypeBase {

  /**
   * Get the default items.
   *
   * @return \Drupal\checklist\Entity\ChecklistItemInterface[]
   *   The checklist.
   */
  public function getDefaultItems(): array {
    $items = [
      'AC01' => $this->itemStorage()->create([
        'checklist_type' => $this->getPluginId(),
        'name' => 'AC01',
        'title' => 'First Item on the Checklist',
        'handler' => [
          'id' => 'simply_checkable',
          'configuration' => [],
        ],
      ]),
      'AC02' => $this->itemStorage()->create([
        'checklist_type' => $this->getPluginId(),
        'name' => 'AC02',
        'title' => 'Second Item on the Checklist',
        'handler' => [
          'id' => 'simply_checkable',
          'configuration' => [],
        ],
      ]),
      'AC03' => $this->itemStorage()->create([
        'checklist_type' => $this->getPluginId(),
        'name' => 'AC03',
        'title' => 'Third Item on the Checklist',
        'handler' => [
          'id' => 'simply_checkable',
          'configuration' => [],
        ],
      ]),
      'AC04' => $this->itemStorage()->create([
        'checklist_type' => $this->getPluginId(),
        'name' => 'AC04',
        'title' => 'Fourth Item on the Checklist',
        'handler' => [
          'id' => 'simply_checkable',
          'configuration' => [],
        ],
      ]),
    ];
    return $items;
  }

}
