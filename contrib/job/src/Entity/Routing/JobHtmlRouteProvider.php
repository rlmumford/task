<?php

namespace Drupal\task_job\Entity\Routing;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\entity\Routing\DefaultHtmlRouteProvider;
use Symfony\Component\Routing\Route;

/**
 * Html route provider for the job entity.
 *
 * @package Drupal\task_job\Entity\Routing
 */
class JobHtmlRouteProvider extends DefaultHtmlRouteProvider {

  /**
   * Define the enable form route.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   *
   * @return \Symfony\Component\Routing\Route
   *   The route definition.
   */
  protected function getEnableFormRoute(EntityTypeInterface $entity_type) {
    if ($entity_type->hasLinkTemplate('enable-form') && $entity_type->getFormClass('enable')) {
      $entity_type_id = $entity_type->id();
      $route = new Route($entity_type->getLinkTemplate('enable-form'));

      $route
        ->setDefaults([
          '_entity_form' => "{$entity_type_id}.enable",
          '_title_callback' => '\Drupal\Core\Entity\Controller\EntityController::editTitle',
        ])
        ->setRequirement('_entity_access', "{$entity_type_id}.enable")
        ->setOption('parameters', [
          $entity_type_id => ['type' => 'entity:' . $entity_type_id],
        ]);

      // Entity types with serial IDs can specify this in their route
      // requirements, improving the matching process.
      if ($this->getEntityTypeIdKeyType($entity_type) === 'integer') {
        $route->setRequirement($entity_type_id, '\d+');
      }
      return $route;
    }
  }

  /**
   * Define the disable form route.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   *
   * @return \Symfony\Component\Routing\Route
   *   The route definition.
   */
  protected function getDisableFormRoute(EntityTypeInterface $entity_type) {
    if ($entity_type->hasLinkTemplate('disable-form') && $entity_type->getFormClass('disable')) {
      $entity_type_id = $entity_type->id();
      $route = new Route($entity_type->getLinkTemplate('disable-form'));

      $route
        ->setDefaults([
          '_entity_form' => "{$entity_type_id}.disable",
          '_title_callback' => '\Drupal\Core\Entity\Controller\EntityController::editTitle',
        ])
        ->setRequirement('_entity_access', "{$entity_type_id}.disable")
        ->setOption('parameters', [
          $entity_type_id => ['type' => 'entity:' . $entity_type_id],
        ]);

      // Entity types with serial IDs can specify this in their route
      // requirements, improving the matching process.
      if ($this->getEntityTypeIdKeyType($entity_type) === 'integer') {
        $route->setRequirement($entity_type_id, '\d+');
      }
      return $route;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getRoutes(EntityTypeInterface $entity_type) {
    $collection = parent::getRoutes($entity_type);

    if ($route = $this->getEnableFormRoute($entity_type)) {
      $collection->add("entity.{$entity_type->id()}.enable_form", $route);
    }
    if ($route = $this->getDisableFormRoute($entity_type)) {
      $collection->add("entity.{$entity_type->id()}.disable_form", $route);
    }

    return $collection;
  }

}
