<?php

namespace Drupal\task_job\TypedData;

use Drupal\Core\TypedData\DataReferenceBase;

/**
 * Exposes the selected job through the normal entity property.
 */
class JobReference extends DataReferenceBase {

  /**
   * {@inheritdoc}
   */
  public function getTarget() {
    $job = \Drupal::service('task_job.resolver')->resolve($this->getParent()->getEntity());
    return $job?->getTypedData();
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetIdentifier() {
    return $this->getParent()->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getString() {
    return $this->getValue()?->label() ?? '';
  }

}
