<?php

namespace Drupal\task_dependency;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;

/**
 * Dispatches committed readiness work and preserves newer requests.
 */
class DependencyScheduler {

  public const QUEUE = 'task_dependency_reevaluate';

  /**
   * Constructs the scheduler.
   */
  public function __construct(protected WorkflowStorage $storage, protected QueueFactory $queues, protected EntityTypeManagerInterface $entities, protected AccountSwitcherInterface $switcher) {}

  /**
   * Dispatches a bounded batch, leaving lost deliveries recoverable by expiry.
   */
  public function dispatch(): void {
    foreach ($this->storage->reserve() as $message) {
      $this->queues->get(self::QUEUE)->createItem($message);
    }
  }

  /**
   * Recomputes task status; checklist work uses its own authorized executor.
   */
  public function run(array $message): void {
    if (!$this->storage->pending($message)) {
      return;
    }
    $storage = $this->entities->getStorage('task');
    $storage->resetCache();
    $tasks = $storage->loadByProperties(['uuid' => $message['owner']]);
    $task = reset($tasks);
    if ($task && !in_array($task->get('status')->value, ['resolved', 'closed'], TRUE)) {
      $this->switcher->switchTo(new AnonymousUserSession());
      try {
        $task->save();
      }
      finally {
        $this->switcher->switchBack();
      }
    }
    $this->storage->acknowledge($message);
  }

}
