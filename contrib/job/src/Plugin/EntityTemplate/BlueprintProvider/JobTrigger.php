<?php

namespace Drupal\task_job\Plugin\EntityTemplate\BlueprintProvider;

use Drupal\Component\Plugin\Context\ContextInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\entity_template\BlueprintStorageInterface;
use Drupal\entity_template\Plugin\EntityTemplate\BlueprintProvider\BlueprintProviderInterface;
use Drupal\entity_template\Plugin\EntityTemplate\Builder\BuilderInterface;
use Drupal\task_job\Plugin\EntityTemplate\Builder\JobTaskBuilder;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerManager;
use Drupal\task_job\TaskJobTempstoreRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Blueprint provider for job triggers.
 *
 * @EntityTemplateBlueprintProvider(
 *   id = "job_trigger",
 *   label = @Translation("Job Trigger")
 * )
 *
 * @package Drupal\task_job\Plugin\EntityTemplate\BlueprintProvider
 */
class JobTrigger extends PluginBase implements BlueprintProviderInterface, ContainerFactoryPluginInterface {

  /**
   * The job trigger manager.
   *
   * @var \Drupal\task_job\Plugin\JobTrigger\JobTriggerManager
   */
  protected $jobTriggerManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The job tempstore repository.
   *
   * @var \Drupal\task_job\TaskJobTempstoreRepository
   */
  protected $jobTempstoreRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.task_job.trigger'),
      $container->get('entity_type.manager'),
      $container->get('task_job.tempstore_repository')
    );
  }

  /**
   * JobTrigger constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\task_job\Plugin\JobTrigger\JobTriggerManager $job_trigger_manager
   *   The job trigger manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\task_job\TaskJobTempstoreRepository $job_tempstore_repository
   *   The job tempstore repo.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    JobTriggerManager $job_trigger_manager,
    EntityTypeManagerInterface $entity_type_manager,
    TaskJobTempstoreRepository $job_tempstore_repository
  ) {
    $this->jobTriggerManager = $job_trigger_manager;
    $this->jobTempstoreRepository = $job_tempstore_repository;
    $this->entityTypeManager = $entity_type_manager;

    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllBlueprints(BuilderInterface $builder) {
    if (!$builder instanceof JobTaskBuilder) {
      return [];
    }

    $job = $builder->getJob();
    $bps = [];
    foreach ($job->getTriggersConfiguration() as $trigger => $configuration) {
      $bps[$trigger] = new BlueprintJobTriggerAdaptor(
        $job,
        $this->jobTriggerManager->createInstance($configuration['id'], $configuration)
      );
    }

    return $bps;
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableBlueprints(
    BuilderInterface $builder,
    $parameters = [],
    ?AccountInterface $account = NULL
  ) {
    if (!($builder instanceof JobTaskBuilder) || !isset($parameters['trigger'])) {
      return $this->getAllBlueprints($builder);
    }

    $job = $builder->getJob();
    $triggers = $job->getTriggersConfiguration();
    $trigger = $parameters['trigger'] instanceof ContextInterface ? $parameters['trigger']->getContextValue() : $parameters['trigger'];

    if (!isset($triggers[$trigger])) {
      return [];
    }
    else {
      return [
        $trigger => new BlueprintJobTriggerAdaptor(
          $job,
          $this->jobTriggerManager->createInstance(
            $triggers[$trigger]['id'],
            $triggers[$trigger]
          )
        ),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getBlueprintStorage($id) {
    [$job_id, $trigger] = explode('.', $id, 2);

    /** @var \Drupal\task_job\JobInterface $job */
    $job = $this->entityTypeManager->getStorage('task_job')->load($job_id);

    // Get from the job tempstore if appropriate.
    $job = $this->jobTempstoreRepository->get($job);
    $triggers = $job->getTriggerCollection();

    if (!$triggers->has($trigger)) {
      return NULL;
    }

    return new BlueprintStorageJobTriggerAdaptor(
      $job,
      $triggers->get($trigger),
      $this
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getBlueprintKey(BlueprintStorageInterface $blueprint_storage) {
    if (!($blueprint_storage instanceof BlueprintStorageJobTriggerAdaptor)) {
      throw new \Exception('Invalid blueprint storage provided.');
    }

    $job = $blueprint_storage->getJob();
    $trigger = $blueprint_storage->getTrigger();

    return "{$job->id()}.{$trigger->getKey()}";
  }

  /**
   * {@inheritdoc}
   */
  public function getBlueprintEditUrl(BlueprintStorageInterface $blueprint_storage) {
    if (!($blueprint_storage instanceof BlueprintStorageJobTriggerAdaptor)) {
      throw new \Exception('Invalid blueprint storage provided.');
    }

    return new Url(
      'entity.task_job.edit_form',
      [
        'task_job' => $blueprint_storage->getJob()->id(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getBlueprintEditTemplateUrl(BlueprintStorageInterface $blueprint_storage, string $key): Url {
    if (!($blueprint_storage instanceof BlueprintStorageJobTriggerAdaptor)) {
      throw new \Exception('Invalid blueprint storage provided.');
    }

    return new Url(
      'entity.task_job.edit_form',
      [
        'task_job' => $blueprint_storage->getJob()->id(),
      ]
    );
  }

}
