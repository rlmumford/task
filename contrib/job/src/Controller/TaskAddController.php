<?php

namespace Drupal\task_job\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\entity_template\TemplateBuilderManager;
use Drupal\task_job\Event\SelectJobEnvironmentDetectionEvent;
use Drupal\task_job\Event\TaskJobEvents;
use Drupal\task_job\JobInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for adding tasks.
 */
class TaskAddController extends ControllerBase {

  /**
   * The builder manager.
   *
   * @var \Drupal\entity_template\TemplateBuilderManager
   */
  protected $builderManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity.form_builder'),
      $container->get('plugin.manager.entity_template.builder'),
      $container->get('event_dispatcher'),
      $container->get('current_user')
    );
  }

  /**
   * TaskAddController constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFormBuilderInterface $entity_form_builder
   *   The entity form builder service.
   * @param \Drupal\entity_template\TemplateBuilderManager $template_builder_manager
   *   The template builder manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFormBuilderInterface $entity_form_builder,
    TemplateBuilderManager $template_builder_manager,
    EventDispatcherInterface $event_dispatcher,
    AccountInterface $current_user
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFormBuilder = $entity_form_builder;
    $this->builderManager = $template_builder_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->currentUser = $current_user;
  }

  /**
   * Shows a list of Jobs to create.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $assignee
   *   The assignee required.
   *
   * @return array
   *   The built list of jobs.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function selectJob(?AccountInterface $assignee = NULL) {
    $build = [
      '#theme' => 'item_list',
      '#attributes' => [
        'class' => ['job-select', 'card-list'],
      ],
      '#items' => [],
    ];

    if ($this->moduleHandler()->moduleExists('exec_environment')) {
      $event = new SelectJobEnvironmentDetectionEvent($assignee);
      $this->eventDispatcher->dispatch($event, TaskJobEvents::SELECT_JOB_DETECT_ENVIRONMENT);
      $event->applyEnvironment();
    }

    $cache = new CacheableMetadata();
    $cache->addCacheTags(['task_job_list', 'config:task_job_list']);
    $job_storage = $this->entityTypeManager->getStorage('task_job');
    /** @var \Drupal\task_job\JobInterface $job */
    foreach ($job_storage->loadMultiple() as $job) {
      $cache->addCacheableDependency($job);
      if ($job->status() && $job->hasTrigger('manual')) {
        $route_name = 'task_job.task.add_form';
        $params = ['task_job' => $job->id()];

        if ($assignee) {
          if ($assignee->id() === $this->currentUser()->id()) {
            $route_name = 'task_job.task_board.task.add_form';
          }
          else {
            // @todo This needs to be replaced once we've built user sepecfic
            //   task boards.
            $route_name = 'task_job.task.add_form';
            $params += ['assignee' => $assignee->id()];
          }
        }

        if ($job->getTrigger('manual')->access($cache)) {
          $build['#items'][] = [
            'title' => [
              '#type' => 'html_tag',
              '#tag' => 'h3',
              '#value' => $job->label(),
            ],
            'description' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $job->get('description') ?: '',
            ],
            'link' => [
              '#type' => 'link',
              '#url' => Url::fromRoute($route_name, $params),
              '#title' => $this->t('Select'),
              '#attributes' => [
                'class' => ['button', 'button--small'],
              ],
            ],
          ];
        }
      }
    }
    $cache->applyTo($build);

    if (isset($event)) {
      $event->resetEnvironment();
    }

    return $build;
  }

  /**
   * Shows a list of jobs to create for the given assignee.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $assignee
   *   The assignee required.
   *
   * @return array
   *   The built list of jobs.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function selectJobForAssignee(?AccountInterface $assignee = NULL) {
    if (!$assignee) {
      $assignee = $this->currentUser();
    }

    return $this->selectJob($assignee);
  }

  /**
   * Show a form to create a task.
   *
   * @param \Drupal\task_job\JobInterface $task_job
   *   The job to create the task of.
   * @param \Drupal\Core\Session\AccountInterface|null $assignee
   *   The desired assignee of the task.
   *
   * @return array
   *   The build form.
   */
  public function createTask(JobInterface $task_job, ?AccountInterface $assignee = NULL) {
    $task = $task_job->getTrigger('manual')->createTask();
    if ($task && $assignee) {
      $task->assignee = $assignee->id();
    }

    if (!$task) {
      throw new NotFoundHttpException();
    }

    return $this->entityFormBuilder->getForm($task, 'add');
  }

  /**
   * Create task and show task form for a given assignee.
   *
   * @param \Drupal\task_job\JobInterface $task_job
   *   The job to create the task of.
   * @param \Drupal\Core\Session\AccountInterface|null $assignee
   *   The desired assignee of the task.
   *
   * @return array
   *   The build form.
   */
  public function createTaskForAssignee(JobInterface $task_job, ?AccountInterface $assignee = NULL) {
    if (!$assignee) {
      $assignee = $this->currentUser();
    }

    return $this->createTask($task_job, $assignee);
  }

}
