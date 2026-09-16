<?php

namespace Drupal\task_job\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Url;
use Drupal\task\Event\CollectResourcesContextsEvent;
use Drupal\task\Event\TaskEvents;
use Drupal\task_job\JobInterface;
use Drupal\task_job\TaskJobTempstoreRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Controller for choosing a block.
 *
 * @package Drupal\task_job\Controller
 */
class ChooseBlockController extends ControllerBase {
  use AjaxHelperTrait;

  /**
   * The tempstore repo.
   *
   * @var \Drupal\task_job\TaskJobTempstoreRepository
   */
  protected $tempstoreRepository;

  /**
   * The block manager.
   *
   * @var \Drupal\Core\Block\BlockManagerInterface
   */
  protected $blockManager;

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
      $container->get('task_job.tempstore_repository'),
      $container->get('plugin.manager.block'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * ChooseBlockController constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\task_job\TaskJobTempstoreRepository $tempstore_repository
   *   The tempstore repo.
   * @param \Drupal\Core\Block\BlockManagerInterface $block_manager
   *   The block manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TaskJobTempstoreRepository $tempstore_repository,
    BlockManagerInterface $block_manager,
    EventDispatcherInterface $event_dispatcher
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->tempstoreRepository = $tempstore_repository;
    $this->blockManager = $block_manager;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * Build the list of available plugins.
   *
   * @param \Drupal\task_job\JobInterface $task_job
   *   The task job.
   *
   * @return array
   *   The build array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function build(JobInterface $task_job) {
    if ($this->tempstoreRepository->has($task_job)) {
      $task_job = $this->tempstoreRepository->get($task_job);
    }

    /** @var \Drupal\task\Entity\Task $temp_task */
    $temp_task = $this->entityTypeManager->getStorage('task')->create([
      'job' => $task_job,
    ]);
    $collect_resource_contexts = new CollectResourcesContextsEvent($temp_task);
    $this->eventDispatcher->dispatch($collect_resource_contexts, TaskEvents::COLLECT_RESOURCES_CONTEXTS);
    $definitions = $this->blockManager->getFilteredDefinitions(
      'task_job_resource',
      $collect_resource_contexts->getContexts() + [
        'task' => new EntityContext(
          new EntityContextDefinition('task', $this->t('The Task')),
          $temp_task
        ),
      ]
    );

    $build = [
      '#type' => 'container',
    ];
    foreach ($definitions as $name => $definition) {
      $category = isset($definition['category']) ? (string) $definition['category'] : 'Other';
      if (!isset($build[$category])) {
        $build[$category]['#type'] = 'details';
        $build[$category]['#open'] = TRUE;
        $build[$category]['#title'] = $category;
        $build[$category]['links'] = [
          '#theme' => 'links',
          '#links' => [],
        ];
      }

      $build[$category]['links']['#links'][] = [
        'title' => $definition['admin_label'],
        'url' => Url::fromRoute(
          'task_job.resource.add',
          [
            'task_job' => $task_job->id(),
            'plugin_id' => $name,
          ]
        ),
        'attributes' => $this->getAjaxAttributes(),
      ];
    }

    return $build;
  }

  /**
   * Get the ajax attributes.
   *
   * @return array
   *   The ajax attributes for the buttons.
   */
  protected function getAjaxAttributes() {
    if ($this->isAjax()) {
      return [
        'class' => ['use-ajax'],
        'data-dialog-type' => 'dialog',
        'data-dialog-renderer' => 'off_canvas',
        'data-dialog-options' => Json::encode([
          'width' => '650px',
        ]),
      ];
    }
    return [];
  }

}
