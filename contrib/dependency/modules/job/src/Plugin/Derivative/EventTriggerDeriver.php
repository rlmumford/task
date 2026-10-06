<?php

namespace Drupal\task_dependency_job\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\task_dependency\TriggerManager;
use Drupal\task_dependency\OccurrenceTriggerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Supplies the same event identities to the job creation consumer.
 */
class EventTriggerDeriver extends DeriverBase implements ContainerDeriverInterface {
  use StringTranslationTrait;

  /**
   * Constructs the deriver.
   */
  public function __construct(protected TriggerManager $triggers) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static($container->get('plugin.manager.task_dependency.trigger'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->triggers->getDefinitions() as $id => $definition) {
      [$name, $context] = $this->triggers->bindingDefinition($id);
      $this->derivatives[$id] = [
        'label' => $definition['label'],
        'event' => $id,
        'event_context' => $name,
        'context_definitions' => $definition['context_definitions'] + (is_a($definition['class'], OccurrenceTriggerInterface::class, TRUE) ? [] : [
          'original' => clone $context,
        ]),
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
