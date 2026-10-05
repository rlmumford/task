<?php

namespace Drupal\task_job_additions;

use Drupal\Core\Database\Connection;

/**
 * Persists immutable addition receipts separately from task/form snapshots.
 */
class AdditionStorage {

  public function __construct(protected Connection $database) {}

  /**
   * Loads one receipt by its request/instance UUID.
   */
  public function load(string $id): ?array {
    $receipt = $this->database->select('task_job_checklist_addition', 'a')->fields('a')->condition('id', $id)->execute()->fetchAssoc();
    return $receipt ? $this->normalize($receipt) : NULL;
  }

  /**
   * Loads a task's receipts in stable creation order.
   */
  public function forTask(string $uuid): array {
    $receipts = $this->database->select('task_job_checklist_addition', 'a')->fields('a')->condition('task_uuid', $uuid)->orderBy('created')->orderBy('id')->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    return array_map($this->normalize(...), $receipts);
  }

  /**
   * Inserts a server-authorized receipt inside the manager's transaction.
   */
  public function insert(array $receipt): void {
    $this->database->insert('task_job_checklist_addition')->fields($receipt)->execute();
  }

  /**
   * Removes receipts with their deleted task.
   */
  public function deleteTask(string $uuid): void {
    $this->database->delete('task_job_checklist_addition')->condition('task_uuid', $uuid)->execute();
  }

  /**
   * Keeps API number types consistent across database drivers and replays.
   */
  protected function normalize(array $receipt): array {
    $receipt['actor'] = (int) $receipt['actor'];
    $receipt['created'] = (int) $receipt['created'];
    return $receipt;
  }

}
