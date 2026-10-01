<?php

namespace Drupal\task_dependency_job\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Declares original and replacement contexts for each entity type.
 */
class ReplacementTriggerDeriver extends DeriverBase implements ContainerDeriverInterface {
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
        'label' => $this->t('@type replaced', ['@type' => $definition->getLabel()]),
        'context_definitions' => [
          'original' => EntityContextDefinition::create($type)->setLabel($this->t('Original @type', ['@type' => $definition->getLabel()])),
          'replacement' => EntityContextDefinition::create($type)->setLabel($this->t('Replacement @type', ['@type' => $definition->getLabel()])),
        ],
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
