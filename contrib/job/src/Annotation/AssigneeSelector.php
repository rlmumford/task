<?php

namespace Drupal\task_job\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a selectable source of a job rule assignee.
 *
 * @Annotation
 */
class AssigneeSelector extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The label shown to job configurators.
   *
   * @var \Drupal\Core\Annotation\Translation
   */
  public $label;

}
