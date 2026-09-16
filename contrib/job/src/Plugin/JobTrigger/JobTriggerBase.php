<?php

namespace Drupal\task_job\Plugin\JobTrigger;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity_template\Exception\NoAvailableBlueprintException;
use Drupal\entity_template\TemplateBuilderManager;
use Drupal\task\TaskInterface;
use Drupal\task_job\Event\TaskJobEvents;
use Drupal\task_job\Event\TriggerAccessEvent;
use Drupal\task_job\JobInterface;
use Drupal\task_job\Plugin\EntityTemplate\BlueprintProvider\BlueprintJobTriggerAdaptor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The job trigger base plugin class.
 */
abstract class JobTriggerBase extends PluginBase implements JobTriggerInterface, ContainerFactoryPluginInterface, CacheableDependencyInterface {

  use ContextAwarePluginTrait;

  /**
   * The builder manager service.
   *
   * @var \Drupal\entity_template\TemplateBuilderManager
   */
  protected $builderManager;

  /**
   * The job.
   *
   * @var \Drupal\task_job\JobInterface
   */
  protected $job;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.entity_template.builder'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * JobTriggerBase constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\entity_template\TemplateBuilderManager $builder_manager
   *   The builder manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    TemplateBuilderManager $builder_manager,
    EventDispatcherInterface $event_dispatcher
  ) {
    $this->builderManager = $builder_manager;
    $this->eventDispatcher = $event_dispatcher;

    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getKey(): string {
    return $this->configuration['key'] ??
      $this->getDefaultKey();
  }

  /**
   * Get the default key for this trigger.
   *
   * @return string
   *   Get the default key for this trigger.
   */
  abstract protected function getDefaultKey(): string;

  /**
   * {@inheritdoc}
   */
  public function setJob(JobInterface $job): JobTriggerInterface {
    $this->job = $job;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getJob(): JobInterface {
    return $this->job;
  }

  /**
   * {@inheritdoc}
   */
  public function createTask(): ?TaskInterface {
    /** @var \Drupal\task_job\Plugin\EntityTemplate\Builder\JobTaskBuilder $builder */
    $builder = $this->builderManager->createInstance(
      'task_job:' . $this->getJob()->id()
    );

    $parameters = $this->getContextValues();
    $parameters['job'] = $this->getJob();
    $parameters['trigger'] = $this->getKey();

    try {
      $result = $builder->execute($parameters);

      /** @var \Drupal\task\Entity\Task[] $tasks */
      $tasks = $result->getItems();
      return !empty($tasks) ? reset($tasks) : NULL;
    }
    catch (NoAvailableBlueprintException $e) {
      return NULL;
    }
  }

  /**
   * Gets this plugin's configuration.
   *
   * @return array
   *   An array of this plugin's configuration.
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $configuration + $this->defaultConfiguration();
  }

  /**
   * Gets default configuration for this plugin.
   *
   * @return array
   *   An associative array with the default configuration.
   */
  public function defaultConfiguration() {
    return [
      'template' => [
        'id' => 'default',
        'uuid' => 'default',
        'label' => new TranslatableMarkup('Template'),
        'conditions' => [],
        'components' => (new BlueprintJobTriggerAdaptor($this->getJob(), $this))
          ->getDefaultTemplateComponents(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function access(?CacheableMetadata $cache_metadata = NULL) {
    $access_event = new TriggerAccessEvent($this);
    $this->eventDispatcher->dispatch($access_event, TaskJobEvents::TRIGGER_ACCESS);

    $access = ($access_event->hasAccessResult() ? $access_event->getAccessResult() : AccessResult::allowed());
    if ($cache_metadata && $access instanceof CacheableDependencyInterface) {
      $cache_metadata->addCacheableDependency($access);
    }

    $storage = new BlueprintJobTriggerAdaptor($this->getJob(), $this);
    $template = $storage->getTemplate('default');

    return $access->andIf(AccessResult::allowedIf($template->applies($cache_metadata)))
      ->isAllowed();
  }

}
