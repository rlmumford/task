<?php

namespace Drupal\task_job\Entity;

use Drupal\Component\Plugin\LazyPluginCollection;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\task_job\JobInterface;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface;
use Drupal\task_job\Plugin\JobTrigger\LazyJobTriggerCollection;
use Drupal\typed_data\Context\ContextDefinition;

/**
 * Entity class for the Job entity.
 *
 * @ConfigEntityType(
 *   id = "task_job",
 *   label = @Translation("Task Job"),
 *   label_collection = @Translation("Task Jobs"),
 *   admin_permission = "administer task jobs",
 *   config_prefix = "task_job",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "context",
 *     "description",
 *     "resources",
 *     "default_checklist",
 *     "triggers",
 *     "assignment",
 *   },
 *   handlers = {
 *     "list_builder" = "Drupal\task_job\Controller\JobListBuilder",
 *     "access" = "Drupal\task_job\Entity\JobAccessControlHandler",
 *     "permission_provider" = "Drupal\entity\EntityPermissionProvider",
 *     "form" = {
 *        "add" = "\Drupal\task_job\Form\JobForm",
 *        "default" = "\Drupal\task_job\Form\JobEditForm",
 *        "delete" = "\Drupal\Core\Entity\EntityDeleteForm",
 *        "disable" = "\Drupal\task_job\Form\JobDisableForm",
 *        "enable" = "\Drupal\task_job\Form\JobEnableForm",
 *      },
 *     "route_provider" = {
 *       "html" = "Drupal\task_job\Entity\Routing\JobHtmlRouteProvider",
 *     }
 *   },
 *   links = {
 *     "collection" = "/admin/config/task/job",
 *     "add-form" = "/admin/config/task/job/add",
 *     "canonical" = "/admin/config/task/job/{task_job}",
 *     "edit-form" = "/admin/config/task/job/{task_job}/edit",
 *     "disable-form" = "/admin/config/task/job/{task_job}/disable",
 *     "enable-form" = "/admin/config/task/job/{task_job}/enable",
 *     "delete-form" = "/admin/config/task/job/{task_job}/delete",
 *   }
 * );
 *
 * @package Drupal\task_job\Entity
 */
class Job extends ConfigEntityBase implements JobInterface, EntityWithPluginCollectionInterface {

  /**
   * The default assignment rule; explicit task assignees take precedence.
   *
   * @var string
   */
  protected $assignment = 'service_manager';

  /**
   * The triggers configuration.
   *
   * @var array
   */
  protected $triggers = [];

  /**
   * The trigger collection.
   *
   * @var \Drupal\Component\Plugin\LazyPluginCollection
   */
  protected $triggerCollection;

  /**
   * The resources configuration.
   *
   * @var array
   */
  protected $resources = [];

  /**
   * The resources collection.
   *
   * @var \Drupal\Component\Plugin\LazyPluginCollection
   */
  protected $resourcesCollection;

  /**
   * The default checklist configuration.
   *
   * @var array
   *
   * @codingStandardsIgnoreStart
   */
  protected $default_checklist = [];
  // @codingStandardsIgnoreEnd

  /**
   * The context required by this job.
   *
   * @var array
   *   Array of context configuration, each item has the following keys:
   *   - key: The name of the context.
   *   - label: The human readable label of the context.
   *   - type: The type of the context.
   *   - description: The description of this context.
   *   - multiple: True if the context accepts multiple of the value.
   *   - required: True if the context is required, FALSE otherwise.
   */
  protected $context = [];

  /**
   * Get the default checklist items for this job.
   *
   * @return array
   *   An array of checklist item configuration keyed by the name.
   *   Each item should have atleast the following keys:
   *     - name - The name of the checklist itm
   *     - label - The label of the checklist item
   *     - handler - The handler plugin used for the checklist item.
   *     - handler_configuration - The configuration to be passed to the plugin.
   */
  public function getChecklistItems(): array {
    return $this->get('default_checklist') ?: [];
  }

  /**
   * {@inheritdoc}
   */
  public function getResourcesConfiguration(): array {
    return $this->get('resources') ?: [];
  }

  /**
   * {@inheritdoc}
   */
  public function getResourcesCollection(): LazyPluginCollection {
    if (!$this->resourcesCollection) {
      $this->resourcesCollection = new DefaultLazyPluginCollection(
        \Drupal::service('plugin.manager.block'),
        $this->getResourcesConfiguration()
      );
    }

    return $this->resourcesCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function getTriggersConfiguration(): array {
    return $this->get('triggers') ?: [];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultTriggersConfiguration(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getTriggerCollection(): LazyPluginCollection {
    if (!$this->triggerCollection) {
      $this->triggerCollection = new LazyJobTriggerCollection(
        $this,
        \Drupal::service('plugin.manager.task_job.trigger'),
        $this->getTriggersConfiguration() ?: $this->defaultTriggersConfiguration()
      );
    }

    return $this->triggerCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function getTrigger(string $key): ?JobTriggerInterface {
    return $this->getTriggerCollection()->get($key);
  }

  /**
   * {@inheritdoc}
   */
  public function hasTrigger(string $key): bool {
    $triggers = $this->getTriggersConfiguration();
    return isset($triggers[$key]);
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinitions() {
    $definitions = [];

    foreach ($this->context as $key => $context) {
      $definitions[$key] = ContextDefinition::createFromArray($context);
    }

    return $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinition(string $key) {
    return isset($this->context[$key]) ? ContextDefinition::createFromArray($this->context[$key]) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function addContextDefinition(string $key, ContextDefinition $context_definition) {
    $this->context[$key] = $context_definition->toArray();
  }

  /**
   * {@inheritdoc}
   */
  public function removeContextDefinition(string $key) {
    unset($this->context[$key]);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    if (!$update) {
      \Drupal::service('plugin.manager.entity_template.builder')
        ->clearCachedDefinitions();
    }

    /** @var \Drupal\task_job\Plugin\JobTrigger\JobTriggerManagerInterface $trigger_manager */
    $trigger_manager = \Drupal::service('plugin.manager.task_job.trigger');
    $trigger_manager->updateTriggerIndex($this);
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return [
      'triggers' => $this->getTriggerCollection(),
      'resources' => $this->getResourcesCollection(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $manager = \Drupal::service('plugin.manager.checklist_item_handler');
    foreach ($this->getChecklistItems() as $item) {
      $handler = $manager->createInstance($item['handler'], $item['handler_configuration']);
      $dependencies = $handler instanceof DependentPluginInterface ? $handler->calculateDependencies() : [];
      $dependencies['module'][] = $handler->getPluginDefinition()['provider'];
      foreach ($dependencies as $type => $names) {
        foreach ($names as $name) {
          $this->addDependency($type, $name);
        }
      }
    }
    return $this;
  }

}
