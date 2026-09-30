<?php

namespace Drupal\task_checklist;

/**
 * Stores durable requests separately from the configurable queue transport.
 */
interface TaskChecklistRequestStorageInterface {

  /**
   * Records a request in the caller's transaction without running any work.
   */
  public function request(int $task_id, string $task_uuid, int $executor): void;

  /**
   * Reserves committed requests, coalesced by task and execution account.
   *
   * @return array
   *   Messages containing task_id, task_uuid, executor and request_ids.
   */
  public function reserve(int $limit = 50): array;

  /**
   * Checks whether the message still represents outstanding committed work.
   */
  public function pending(array $message): bool;

  /**
   * Acknowledges only the exact requests reserved for this message.
   */
  public function acknowledge(array $message): void;

  /**
   * Removes requests when their task is deleted.
   */
  public function delete(string $task_uuid): void;

}
