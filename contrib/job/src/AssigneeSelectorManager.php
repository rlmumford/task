<?php

namespace Drupal\task_job;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\task_job\Annotation\AssigneeSelector;

/**
 * Discovers assignee selectors without coupling jobs to application domains.
 */
class AssigneeSelectorManager extends DefaultPluginManager {

  /**
   * Constructs the plugin manager.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache, ModuleHandlerInterface $modules) {
    parent::__construct('Plugin/AssigneeSelector', $namespaces, $modules, AssigneeSelectorInterface::class, AssigneeSelector::class);
    $this->alterInfo('task_job_assignee_selector_info');
    $this->setCacheBackend($cache, 'task_job_assignee_selector_info');
  }

}
