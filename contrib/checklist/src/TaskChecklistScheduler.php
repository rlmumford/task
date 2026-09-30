<?php

namespace Drupal\task_checklist;

use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Dispatches committed checklist wake-ups without processing tasks in cron.
 */
class TaskChecklistScheduler {

  public const QUEUE = 'task_checklist_process';

  /**
   * Constructs the scheduler.
   */
  public function __construct(protected TaskChecklistRequestStorageInterface $storage, protected QueueFactory $queueFactory, protected LoggerInterface $logger) {}

  /**
   * Enqueues a bounded batch; expired reservations repair lost deliveries.
   */
  public function dispatch(int $limit = 50): int {
    $messages = $this->storage->reserve($limit);
    $queue = $this->queueFactory->get(self::QUEUE);
    $count = 0;
    foreach ($messages as $message) {
      try {
        if ($queue->createItem($message) === FALSE) {
          throw new \RuntimeException('Queue rejected the request.');
        }
        $count++;
      }
      catch (\Throwable) {
        $this->logger->error('Task checklist delivery failed for task {task}; scheduling will retry.', ['task' => $message['task_id']]);
      }
    }
    return $count;
  }

}
