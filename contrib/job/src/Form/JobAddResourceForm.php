<?php

namespace Drupal\task_job\Form;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Plugin\PluginFormFactoryInterface;
use Drupal\task\Event\CollectResourcesContextsEvent;
use Drupal\task\Event\TaskEvents;
use Drupal\task_job\JobInterface;
use Drupal\task_job\TaskJobTempstoreRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Form to add a resource block.
 *
 * @package Drupal\task_job\Form
 */
class JobAddResourceForm extends JobPluginFormBase {

  /**
   * The entity type manager.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('task_job.tempstore_repository'),
      $container->get('plugin_form.factory'),
      $container->get('plugin.manager.block'),
      $container->get('config.factory'),
      $container->get('event_dispatcher'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * JobAddResourceForm constructor.
   *
   * @param \Drupal\task_job\TaskJobTempstoreRepository $tempstore_repository
   *   The tempstore repository.
   * @param \Drupal\Core\Plugin\PluginFormFactoryInterface $plugin_form_factory
   *   The plugin form factory.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $manager
   *   The plugin manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    TaskJobTempstoreRepository $tempstore_repository,
    PluginFormFactoryInterface $plugin_form_factory,
    PluginManagerInterface $manager,
    ConfigFactoryInterface $config_factory,
    EventDispatcherInterface $event_dispatcher,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($tempstore_repository, $plugin_form_factory, $manager, $config_factory);

    $this->eventDispatcher = $event_dispatcher;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_add_resource_form';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    /** @var \Drupal\Core\Block\BlockPluginInterface $block */
    $block = $form_state->get('plugin');
    /** @var \Drupal\task_job\JobInterface $job */
    $job = $form_state->get('job');

    $uuid = $form_state->get('resource_uuid') ?: \Drupal::service('uuid')->generate();
    $resources = $job->getResourcesCollection()->getConfiguration();
    $resources[$uuid] = [
      'id' => $block->getPluginId(),
      'uuid' => $uuid,
    ] + $block->getConfiguration();
    $job->set('resources', $resources);
    $job->getResourcesCollection()->setConfiguration($job->getResourcesConfiguration());

    $this->tempstoreRepository->set($job);
  }

  /**
   * {@inheritdoc}
   */
  protected function gatherContexts(JobInterface $task_job) {
    /** @var \Drupal\task\Entity\Task $tmp_task */
    $tmp_task = $this->entityTypeManager->getStorage('task')->create([
      'job' => $task_job,
    ]);
    $event = new CollectResourcesContextsEvent($tmp_task);
    $this->eventDispatcher->dispatch($event, TaskEvents::COLLECT_RESOURCES_CONTEXTS);

    return $event->getContexts() + [
      'task' => new EntityContext(
          new EntityContextDefinition('task', $this->t('The Task')),
          $tmp_task
      ),
    ];
  }

}
