<?php

namespace Drupal\task_job\Plugin\EntityTemplate\Component;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\Plugin\PluginWithFormsTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity_template\Plugin\EntityTemplate\Component\ComponentBase;
use Drupal\entity_template\Plugin\EntityTemplate\Component\ComponentInterface;
use Drupal\entity_template\Plugin\EntityTemplate\Component\SwappableComponentInterface;
use Drupal\entity_template\Plugin\EntityTemplate\Component\TemplateContextAwareComponentInterface;
use Drupal\entity_template\Plugin\EntityTemplate\Component\TemplateContextAwareComponentTrait;
use Drupal\entity_template\Plugin\EntityTemplate\Template\BlueprintTemplateInterface;
use Drupal\entity_template_ui\Form\TemplateUIHelperTrait;
use Drupal\task_job\Plugin\EntityTemplate\BlueprintProvider\BlueprintJobTriggerAdaptor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Abstract class for task context template plugins.
 *
 * @package Drupal\task_job\Plugin\EntityTemplate\Component
 */
abstract class TaskContextBase extends ComponentBase implements TemplateContextAwareComponentInterface, ContainerFactoryPluginInterface, PluginWithFormsInterface, SwappableComponentInterface {
  use PluginWithFormsTrait;
  use TemplateContextAwareComponentTrait, TemplateUIHelperTrait {
    TemplateContextAwareComponentTrait::getTemplate insteadof TemplateUIHelperTrait;
  }

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * TaskContextDataSelect constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    $definitions = $this->getTaskContextDefinitions();
    return !empty($this->configuration['task_context']) && !empty($definitions[$this->configuration['task_context']]) ?
      new TranslatableMarkup(
        '@context_name Context',
        [
          '@context_name' => $definitions[$this->configuration['task_context']]->getLabel(),
        ]
      ) :
      parent::label();
  }

  /**
   * Get the task context definitions.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface[]
   *   The task context data definitions keyed by key.
   */
  public function getTaskContextDefinitions() {
    /** @var \Drupal\task_job\Entity\Job $job */
    $job = NULL;
    $template = $this->getTemplate();
    if (
      $template instanceof BlueprintTemplateInterface &&
      $template->getBlueprint() instanceof BlueprintJobTriggerAdaptor
    ) {
      $job = $template->getBlueprint()->getJob();
    }

    $task = $this->entityTypeManager->getStorage('task')->create([
      'job' => $job,
    ]);
    return $task->get('context')->getPropertyDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function isSwappable(): bool {
    return !empty($this->canSwapTo());
  }

  /**
   * {@inheritdoc}
   */
  public function canSwapTo(): array {
    if ($this->getPluginId() === 'task_context.data_select') {
      return [
        'task_context.widget_input' => new TranslatableMarkup('Direct Input'),
      ];
    }
    else {
      return [
        'task_context.data_select' => new TranslatableMarkup('Value Select'),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function swapConfigurationTo(ComponentInterface $component): ComponentInterface {
    $configuration = $component->getConfiguration();
    $configuration['task_context'] = $this->configuration['task_context'];
    $component->setConfiguration($configuration);
    return $component;
  }

}
