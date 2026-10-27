<?php

namespace Drupal\task_related_document_test\Plugin\ChecklistType;

use Drupal\checklist\Plugin\ChecklistType\ChecklistTypeBase;

/**
 * Provides a task-hosted checklist for outcome fixtures.
 *
 * @ChecklistType(
 *   id = "document_work_test",
 *   label = @Translation("Document work"),
 *   entity_type = "task"
 * )
 */
final class Work extends ChecklistTypeBase {}
