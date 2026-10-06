<?php

namespace Drupal\task_dependency_job\Plugin\JobTrigger;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\task_dependency\TriggerManager;
use Drupal\task_dependency\OccurrenceTriggerInterface;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates jobs using the same pure event matchers as task dependencies.
 *
 * @JobTrigger(
 *   id = "dependency_event",
 *   label = @Translation("Dependency event"),
 *   category = @Translation("Workflow events"),
 *   deriver = "Drupal\task_dependency_job\Plugin\Derivative\EventTriggerDeriver"
 * )
 */
class DependencyEvent extends JobTriggerBase {

  /**
   * The shared event matcher manager.
   */
  protected TriggerManager $events;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->events = $container->get('plugin.manager.task_dependency.trigger');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultKey(): string {
    return $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Create work when this event occurs.');
  }

  /**
   * {@inheritdoc}
   */
  public function access(?CacheableMetadata $cache_metadata = NULL) {
    $matcher = $this->events->createInstance($this->pluginDefinition['event'], $this->configuration['event_configuration'] ?? []);
    return ($matcher instanceof OccurrenceTriggerInterface || $matcher->matches($this->getContextValue($this->pluginDefinition['event_context']), $this->getContextValue('original'))) && parent::access($cache_metadata);
  }

}
