<?php

namespace Drupal\task_job;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Adds draft-specific template tabs at render time, outside plugin discovery.
 */
class JobTemplateLocalTasks {

  /**
   * Constructs the local-task builder.
   */
  public function __construct(protected TaskJobTempstoreRepository $drafts, protected RouteMatchInterface $routeMatch) {}

  /**
   * Builds secondary local tasks from this editor's effective job draft.
   */
  public function alter(array &$data, RefinableCacheableDependencyInterface $cacheability): void {
    // Draft labels and unsaved templates must not enter a shared cache.
    $cacheability->setCacheMaxAge(0);
    unset($data['tabs'][1]['task_job.templates.template']);
    $job = $this->routeMatch->getParameter('task_job');
    if (!$job instanceof JobInterface || !$job->access('update')) {
      return;
    }
    try {
      $job = $this->drafts->get($job);
    }
    catch (AccessDeniedHttpException) {
      // The form reports the lock; do not expose another owner's draft tabs.
      return;
    }
    foreach ($job->get('checklist_templates') ?: [] as $name => $template) {
      $data['tabs'][1]['task_job.template.' . $name] = [
        '#theme' => 'menu_local_task',
        '#link' => [
          'title' => $template['label'],
          'url' => Url::fromRoute('entity.task_job.edit_template', ['task_job' => $job->id(), 'template' => $name]),
          'localized_options' => ['attributes' => ['data-task-job-section' => 'templates']],
        ],
        '#active' => $this->routeMatch->getParameter('template') === $name,
        '#weight' => 0,
      ];
    }
  }

}
