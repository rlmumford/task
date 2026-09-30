<?php

namespace Drupal\task_checklist;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Transactional SQL outbox with bounded, recoverable dispatch reservations.
 */
class TaskChecklistRequestStorage implements TaskChecklistRequestStorageInterface {

  /**
   * Constructs request storage.
   */
  public function __construct(protected Connection $database, protected TimeInterface $time) {}

  /**
   * {@inheritdoc}
   */
  public function request(int $task_id, string $task_uuid, int $executor): void {
    $this->database->insert('task_checklist_request')->fields([
      'task_id' => $task_id,
      'task_uuid' => $task_uuid,
      'executor' => $executor,
      'dispatch_expires' => 0,
    ])->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function reserve(int $limit = 50): array {
    $this->assertCommitted();
    if ($limit < 1 || $limit > 100) {
      throw new \InvalidArgumentException('Dispatch batch must be between one and 100.');
    }
    $now = $this->time->getCurrentTime();
    $rows = $this->database->select('task_checklist_request', 'r')
      ->fields('r', ['id', 'task_id', 'task_uuid', 'executor'])
      ->condition('dispatch_expires', $now, '<=')
      ->orderBy('id')->range(0, $limit)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $messages = [];
    foreach ($rows as $row) {
      $updated = $this->database->update('task_checklist_request')
        ->fields(['dispatch_expires' => $now + 300])
        ->condition('id', $row['id'])
        ->condition('dispatch_expires', $now, '<=')
        ->execute();
      if ($updated) {
        $key = $row['task_uuid'] . ':' . $row['executor'];
        $messages[$key] ??= [
          'task_id' => $row['task_id'],
          'task_uuid' => $row['task_uuid'],
          'executor' => $row['executor'],
          'request_ids' => [],
        ];
        $messages[$key]['request_ids'][] = $row['id'];
      }
    }
    return array_values($messages);
  }

  /**
   * {@inheritdoc}
   */
  public function pending(array $message): bool {
    $this->assertCommitted();
    return (bool) $this->database->select('task_checklist_request', 'r')
      ->condition('task_uuid', $message['task_uuid'])
      ->condition('task_id', $message['task_id'])
      ->condition('executor', $message['executor'])
      ->condition('id', $message['request_ids'], 'IN')
      ->countQuery()->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function acknowledge(array $message): void {
    // IDs alone are not commit ordered: a lower ID can commit after dispatch.
    // Delete only the exact set that was visible when reserving this message.
    $this->database->delete('task_checklist_request')
      ->condition('task_uuid', $message['task_uuid'])
      ->condition('executor', $message['executor'])
      ->condition('id', $message['request_ids'], 'IN')->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $task_uuid): void {
    $this->database->delete('task_checklist_request')->condition('task_uuid', $task_uuid)->execute();
  }

  /**
   * Never dispatch or execute work from an uncommitted entity save.
   */
  protected function assertCommitted(): void {
    if ($this->database->inTransaction()) {
      throw new \LogicException('Task checklist requests must be committed before delivery or execution.');
    }
  }

}
