<?php

namespace Drupal\task_job;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\task_job\Annotation\JobTriggerAction;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface;

/**
 * Discovers and executes configured actions after a job trigger matches.
 */
class TriggerActionManager extends DefaultPluginManager {

  /**
   * Constructs the manager.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache, ModuleHandlerInterface $modules, protected ContextHandlerInterface $contexts) {
    parent::__construct('Plugin/JobTriggerAction', $namespaces, $modules, TriggerActionInterface::class, JobTriggerAction::class);
    $this->alterInfo('task_job_trigger_action_info');
    $this->setCacheBackend($cache, 'task_job_trigger_action_info');
  }

  /**
   * Runs the configured effect; existing configurations default to creation.
   */
  public function execute(JobTriggerInterface $trigger, bool $save): array {
    $configuration = $trigger->getConfiguration()['action'] ?? [];
    $action = $this->createInstance($configuration['plugin'] ?? 'create_task', $configuration['configuration'] ?? []);
    $this->contexts->applyContextMapping($action, $trigger->getContexts());
    return $action->execute($trigger, $save);
  }

}
