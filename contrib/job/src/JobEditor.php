<?php

namespace Drupal\task_job;

use Drupal\Core\Url;

/**
 * Shares editor links between configuration and content job route families.
 */
final class JobEditor {

  /**
   * Builds a link using the route family declared by this job's entity type.
   */
  public static function url(JobInterface $job, string $route, array $parameters = [], array $options = []): Url {
    $prefix = $job->getEntityType()->get('task_job_route_prefix') ?? 'task_job';
    return Url::fromRoute(str_replace('task_job', $prefix, $route), $parameters, $options);
  }

}
