<?php

namespace Drupal\task_job\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines an action performed by a matched job trigger.
 *
 * @Annotation
 */
class JobTriggerAction extends Plugin {

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
