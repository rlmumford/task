<?php

namespace Drupal\task_ui\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;

/**
 * Suggests services visible through both query and individual entity access.
 *
 * @EntityReferenceSelection(
 *   id = "task_ui_service",
 *   label = @Translation("Task workspace service"),
 *   entity_types = {"service"},
 *   group = "task_ui_service",
 *   weight = 0
 * )
 */
final class Service extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    $options = parent::getReferenceableEntities($match, $match_operator, $limit);
    foreach ($options as $bundle => $labels) {
      // Reuse the parent's cached entities and translated, escaped labels.
      $services = $this->entityTypeManager->getStorage('service')->loadMultiple(array_keys($labels));
      foreach ($services as $id => $service) {
        // Entity access can restrict the query-scoped candidates further.
        if (!$service->access('view')) {
          unset($options[$bundle][$id]);
        }
      }
    }
    return $options;
  }

}
