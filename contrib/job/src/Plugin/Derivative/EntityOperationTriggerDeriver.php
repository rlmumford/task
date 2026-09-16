<?php

namespace Drupal\task_job\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity operation deriver.
 */
class EntityOperationTriggerDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The base plugin id.
   *
   * @var string
   */
  protected $basePluginId;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $base_plugin_id,
      $container->get('entity_type.manager')
    );
  }

  /**
   * ConfigTemplateBuilderDeriver constructor.
   *
   * @param string $base_plugin_id
   *   The base plugin id.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(string $base_plugin_id, EntityTypeManagerInterface $entity_type_manager) {
    $this->basePluginId = $base_plugin_id;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type) {
      if (!$entity_type instanceof ContentEntityType) {
        continue;
      }

      $context_definitions = [];
      $context_definitions[$entity_type->id()] = new EntityContextDefinition(
        'entity:' . $entity_type->id(),
        $entity_type->getLabel()
      );

      $this->derivatives["{$entity_type->id()}.insert"] = [
        'label' => new TranslatableMarkup(
            '@entity_type Insert',
            ['@entity_type' => $entity_type->getLabel()]
        ),
        'entity_type_id' => $entity_type->id(),
        'operation' => 'insert',
        'operation_past_label' => 'inserted',
        'context_definitions' => $context_definitions,
      ] + $base_plugin_definition;
      $this->derivatives["{$entity_type->id()}.delete"] = [
        'label' => new TranslatableMarkup(
            '@entity_type Delete',
            ['@entity_type' => $entity_type->getLabel()]
        ),
        'entity_type_id' => $entity_type->id(),
        'operation' => 'delete',
        'operation_past_label' => 'deleted',
        'context_definitions' => $context_definitions,
      ] + $base_plugin_definition;

      $context_definitions["original_{$entity_type->id()}"] = new EntityContextDefinition(
        'entity:' . $entity_type->id(),
        new TranslatableMarkup(
          'Original @entity_type',
          ['@entity_type' => $entity_type->getLabel()]
        )
      );
      $this->derivatives["{$entity_type->id()}.update"] = [
        'label' => new TranslatableMarkup(
            '@entity_type Update',
            ['@entity_type' => $entity_type->getLabel()]
        ),
        'entity_type_id' => $entity_type->id(),
        'operation' => 'update',
        'operation_past_label' => 'updated',
        'context_definitions' => $context_definitions,
      ] + $base_plugin_definition;
    }

    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

}
