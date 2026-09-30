<?php

namespace Drupal\task_dependency\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines concrete entity contexts shared by jobs and dependencies.
 */
class EntityStateDeriver extends DeriverBase implements ContainerDeriverInterface {
  use StringTranslationTrait;

  /**
   * Constructs the deriver.
   */
  public function __construct(protected EntityTypeManagerInterface $entities) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->entities->getDefinitions() as $type => $definition) {
      if (!$definition->entityClassImplements('Drupal\Core\Entity\FieldableEntityInterface') || !$definition->getKey('uuid') || $type === 'task_dependency') {
        continue;
      }
      $this->derivatives[$type] = [
        'label' => $this->t('@type enters a state', ['@type' => $definition->getLabel()]),
        'context_definitions' => [
          'entity' => EntityContextDefinition::create($type)->setLabel($this->t('@type to wait for', ['@type' => $definition->getLabel()])),
        ],
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
