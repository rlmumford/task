<?php

namespace Drupal\task_checklist;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Evaluates committed requests under their saved execution accounts.
 */
class TaskChecklistRequestRunner {

  /**
   * Constructs the request runner.
   */
  public function __construct(
    protected TaskChecklistRequestStorageInterface $storage,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountSwitcherInterface $accountSwitcher,
    protected LockBackendInterface $lock,
    protected TaskChecklistProcessorInterface $processor,
  ) {}

  /**
   * Runs one coalesced request and acknowledges only its reserved request IDs.
   */
  public function run(array $message): void {
    if (!$this->storage->pending($message)) {
      return;
    }
    $name = 'task_checklist:' . $message['task_uuid'];
    if (!$this->lock->acquire($name, 900)) {
      // Leave the durable request for redispatch after its reservation expires.
      return;
    }
    $switched = FALSE;
    try {
      if (!$this->storage->pending($message)) {
        return;
      }
      $this->entityTypeManager->getStorage('user_role')->resetCache();
      $account = $this->entityTypeManager->getStorage('user')->loadUnchanged($message['executor']);
      $task = $this->entityTypeManager->getStorage('task')->loadUnchanged($message['task_id']);
      if (!$task || $task->uuid() !== $message['task_uuid'] || !$account || !$account->isActive() || $account->isAnonymous()) {
        $this->storage->acknowledge($message);
        return;
      }
      $this->accountSwitcher->switchTo($account);
      $switched = TRUE;
      $this->entityTypeManager->getAccessControlHandler('task')->resetCache();
      $this->entityTypeManager->getAccessControlHandler('checklist_item')->resetCache();
      if ($task->access('view') && $task->access('update') && $task->checklist->access('view') && $task->checklist->access('edit')) {
        $this->processor->processTask($task);
      }
      $this->storage->acknowledge($message);
    }
    catch (AccessDeniedHttpException) {
      // Rejected access needs a new authorized save, not endless redelivery.
      $this->storage->acknowledge($message);
    }
    finally {
      if ($switched) {
        $this->accountSwitcher->switchBack();
      }
      $this->lock->release($name);
    }
  }

}
