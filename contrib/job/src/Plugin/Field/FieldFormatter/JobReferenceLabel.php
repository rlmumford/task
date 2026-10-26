<?php

namespace Drupal\task_job\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Displays a job without exposing definitions across access boundaries.
 *
 * @FieldFormatter(
 *   id = "task_job_reference_label",
 *   label = @Translation("Job label"),
 *   field_types = {"task_job_reference"}
 * )
 */
class JobReferenceLabel extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    foreach ($items as $delta => $item) {
      if ($job = $item->entity) {
        $access = $job->access('view', NULL, TRUE);
        $elements[$delta] = ['#plain_text' => $access->isAllowed() ? $job->label() : '', '#access' => $access];
        CacheableMetadata::createFromObject($job)->addCacheableDependency($access)->applyTo($elements[$delta]);
      }
    }
    return $elements;
  }

}
