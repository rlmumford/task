<?php

namespace Drupal\task_job\Plugin\JobTrigger;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity operation triggers.
 *
 * @JobTrigger(
 *   id = "entity_op",
 *   label = @Translation("Entity Operation"),
 *   category = @Translation("Entity Operations"),
 *   description = @Translation("This job gets triggered when an entity operation happens."),
 *   deriver = "\Drupal\task_job\Plugin\Derivative\EntityOperationTriggerDeriver"
 * )
 */
class EntityOperation extends JobTriggerBase implements ContainerFactoryPluginInterface {

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
    return parent::create(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition
    )->setEntityTypeManager($container->get('entity_type.manager'));
  }

  /**
   * Set the entity type manager service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   *
   * @return $this
   */
  public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
    return $this;
  }

  /**
   * Get the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  public function entityTypeManager() : EntityTypeManagerInterface {
    return $this->entityTypeManager;
  }

  /**
   * Get the entity type definition.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface|null
   *   The entity type definition.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getEntityType() {
    return $this->entityTypeManager()->getDefinition($this->pluginDefinition['entity_type_id']);
  }

  /**
   * The operation.
   *
   * @return string
   *   The operation name.
   */
  protected function getOperation() {
    return $this->pluginDefinition['operation'];
  }

  /**
   * Get the past label for the operation.
   *
   * @return string
   *   The past tense label of the operation.
   */
  protected function getOperationPastLabel() {
    return $this->pluginDefinition['operation_past_label'] ??
      $this->pluginDefinition['operation'] . 'd';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return new TranslatableMarkup(
      "@entity_type @operation",
      [
        '@entity_type' => $this->getEntityType()->getLabel(),
        '@operation' => ucfirst($this->getOperation()),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return new TranslatableMarkup(
      "This job is triggered whenever a @entity_type is @operation_past",
      [
        '@entity_type' => $this->getEntityType()->getLabel(),
        '@operation_past' => $this->getOperationPastLabel(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultKey(): string {
    return "{$this->getEntityType()->id()}|{$this->getOperation()}";
  }

}
