<?php

namespace Drupal\task_related_test\Plugin\ChecklistType;

use Drupal\checklist\Plugin\ChecklistType\ChecklistTypeBase;

/**
 * Hosts generic outcome fixtures on tasks.
 *
 * @ChecklistType(
 *   id = "related_work_test",
 *   label = @Translation("Related work"),
 *   entity_type = "task"
 * )
 */
final class RelatedWork extends ChecklistTypeBase {}
