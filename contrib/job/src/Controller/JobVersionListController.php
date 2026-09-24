<?php

namespace Drupal\task_job\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\task_job\JobInterface;
use Drupal\task_job\JobVersionId;
use Drupal\task_job\JobVersionResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the named versions of a task job.
 */
class JobVersionListController extends ControllerBase {

  /**
   * The job version resolver.
   *
   * @var \Drupal\task_job\JobVersionResolverInterface
   */
  protected $jobVersionResolver;

  /**
   * Constructs the controller.
   */
  public function __construct(JobVersionResolverInterface $job_version_resolver) {
    $this->jobVersionResolver = $job_version_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('task_job.version_resolver'));
  }

  /**
   * Builds the version table for a logical job.
   */
  public function versions(JobInterface $task_job): array {
    $job_id = JobVersionId::base($task_job->id());
    $rows = [];

    foreach ($this->jobVersionResolver->listVersions($job_id) as $job) {
      $rows[] = $this->buildRow($job, $this->t('Published'));
    }
    foreach ($this->jobVersionResolver->listDirtyVersions($job_id) as $job) {
      $rows[] = $this->buildRow($job, $this->t('Working copy'));
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Version'),
        $this->t('Status'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No named versions have been created for this job.'),
    ];
  }

  /**
   * Builds a version table row.
   */
  protected function buildRow(JobInterface $job, string $status): array {
    $operations = [
      Link::fromTextAndUrl(
        $this->t('Edit'),
        Url::fromRoute('entity.task_job.edit_form', ['task_job' => $job->id()])
      )->toRenderable(),
    ];
    if ($job->isDirty() && $job->access('publish')) {
      $operations[] = Link::fromTextAndUrl(
        $this->t('Publish'),
        Url::fromRoute('entity.task_job.publish_form', ['task_job' => $job->id()])
      )->toRenderable();
    }

    return [
      'version' => $job->getVersion(),
      'status' => $status,
      'operations' => [
        '#theme' => 'item_list',
        '#items' => $operations,
      ],
    ];
  }

}
