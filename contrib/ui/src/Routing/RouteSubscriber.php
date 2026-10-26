<?php

namespace Drupal\task_ui\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\task_ui\Controller\WorkController;
use Symfony\Component\Routing\RouteCollection;

/**
 * Opts the task canonical page into the shared staff workspace.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('entity.task.canonical')) {
      $defaults = $route->getDefaults();
      unset($defaults['_entity_view']);
      $defaults['_controller'] = WorkController::class . '::task';
      $route->setDefaults($defaults);
    }
  }

}
