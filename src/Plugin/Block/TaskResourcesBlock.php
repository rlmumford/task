<?php

namespace Drupal\task\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\task\TaskResourceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Block for displaying task resources.
 *
 * @Block(
 *   id = "task_resources",
 *   admin_label = @Translation("Task Resources"),
 *   context_definitions = {
 *     "task" = @ContextDefinition("entity:task", label = @Translation("Task"), required = TRUE)
 *   }
 * )
 *
 * @package Drupal\task\Plugin\Block
 */
class TaskResourcesBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The resource manager service.
   *
   * @var \Drupal\task\TaskResourceManagerInterface
   */
  protected $resourceManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('task.resource_manager')
    );
  }

  /**
   * TaskResourcesBlock constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\task\TaskResourceManagerInterface $resource_manager
   *   The resource manager service.
   */
  public function __construct(array $configuration, string $plugin_id, $plugin_definition, TaskResourceManagerInterface $resource_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->resourceManager = $resource_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    return $this->resourceManager->buildTaskResources($this->getContextValue('task'));
  }

}
