<?php

namespace Drupal\task_job\Controller;

use Drupal\checklist\ChecklistContextCollectorInterface;
use Drupal\checklist\ChecklistItemHandlerManager;
use Drupal\checklist\ChecklistTypeManager;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Url;
use Drupal\task_job\Form\JobAddChecklistItemForm;
use Drupal\task_job\JobConfigurationChecklist;
use Drupal\task_job\JobInterface;
use Drupal\task_job\TaskJobTempstoreRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for adding new checklist items to a job.
 */
class ChooseHandlerController extends ControllerBase {
  use AjaxHelperTrait;

  /**
   * The tempstore repo.
   *
   * @var \Drupal\task_job\TaskJobTempstoreRepository
   */
  protected TaskJobTempstoreRepository $tempstoreRepository;

  /**
   * The handler plugin manager.
   *
   * @var \Drupal\checklist\ChecklistItemHandlerManager
   */
  protected $manager;

  /**
   * The context handler service.
   *
   * @var \Drupal\Core\Plugin\Context\ContextHandlerInterface
   */
  protected ContextHandlerInterface $contextHandler;

  /**
   * The context collector.
   *
   * @var \Drupal\checklist\ChecklistContextCollectorInterface
   */
  protected ChecklistContextCollectorInterface $contextCollector;

  /**
   * The checklist type manager.
   *
   * @var \Drupal\checklist\ChecklistTypeManager
   */
  protected ChecklistTypeManager $checklistTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.checklist_item_handler'),
      $container->get('form_builder'),
      $container->get('context.handler'),
      $container->get('checklist.context_collector'),
      $container->get('plugin.manager.checklist_type'),
      $container->get('task_job.tempstore_repository')
    );
  }

  /**
   * ChooseHandlerController constructor.
   *
   * @param \Drupal\checklist\ChecklistItemHandlerManager $manager
   *   The checklist item handler manager.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder service.
   * @param \Drupal\Core\Plugin\Context\ContextHandlerInterface $context_handler
   *   The context handler service.
   * @param \Drupal\checklist\ChecklistContextCollectorInterface $context_collector
   *   The checklist context collector service.
   * @param \Drupal\checklist\ChecklistTypeManager $checklist_type_manager
   *   The checklist type manager.
   * @param \Drupal\task_job\TaskJobTempstoreRepository $tempstore_repository
   *   The job tempstore repo.
   */
  public function __construct(
    ChecklistItemHandlerManager $manager,
    FormBuilderInterface $form_builder,
    ContextHandlerInterface $context_handler,
    ChecklistContextCollectorInterface $context_collector,
    ChecklistTypeManager $checklist_type_manager,
    TaskJobTempstoreRepository $tempstore_repository
  ) {
    $this->manager = $manager;
    $this->formBuilder = $form_builder;
    $this->contextHandler = $context_handler;
    $this->contextCollector = $context_collector;
    $this->checklistTypeManager = $checklist_type_manager;
    $this->tempstoreRepository = $tempstore_repository;
  }

  /**
   * Build the list of checklist item handlers to select from.
   *
   * @param \Drupal\task_job\JobInterface $task_job
   *   The job.
   *
   * @return array
   *   A build array for the page.
   */
  public function build(JobInterface $task_job) {
    if ($this->tempstoreRepository->has($task_job)) {
      $task_job = $this->tempstoreRepository->get($task_job);
    }

    $definitions = $this->manager->getDefinitions();
    $definitions = $this->contextHandler->filterPluginDefinitionsByContexts(
      $this->contextCollector->collectConfigContexts(
        JobConfigurationChecklist::createFromJob($task_job, $this->checklistTypeManager)
      ),
      $definitions
    );

    if (count($definitions) === 1) {
      return $this->formBuilder()->getForm(
        JobAddChecklistItemForm::class,
        $task_job,
        key($definitions)
      );
    }
    else {
      $build = [
        '#type' => 'container',
      ];

      foreach ($definitions as $name => $definition) {
        if ($name === "missing") {
          continue;
        }

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
          'title' => $definition['label'],
          'url' => Url::fromRoute(
            'task_job.checklist_item.add',
            [
              'task_job' => $task_job->id(),
              'handler' => $name,
            ]
          ),
          'attributes' => $this->getAjaxAttributes(),
        ];
      }

      return $build;
    }
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
