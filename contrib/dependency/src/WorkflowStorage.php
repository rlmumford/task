<?php

namespace Drupal\task_dependency;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\task_dependency\Entity\Dependency;

/**
 * Owns transactional locks, exact-ID wake-ups and dependency audit records.
 */
class WorkflowStorage {

  /**
   * Constructs workflow storage.
   */
  public function __construct(protected Connection $database, protected UuidInterface $uuid, protected TimeInterface $time, protected AccountProxyInterface $account) {}

  /**
   * Locks target identities until the source/registration transaction ends.
   */
  public function lockTargets(array $bindings): void {
    if (!$this->database->inTransaction()) {
      throw new \LogicException('Dependency changes require a transaction.');
    }
    $keys = [];
    foreach ($bindings as $binding) {
      $keys[] = hash('sha256', $binding['entity_type'] . ':' . $binding['entity_id']);
    }
    sort($keys);
    foreach (array_unique($keys) as $key) {
      $this->database->merge('task_dependency_target_lock')->key('target', $key)->insertFields(['target' => $key])->execute();
      $this->database->select('task_dependency_target_lock', 'l')->fields('l')->condition('target', $key)->forUpdate()->execute()->fetchField();
    }
  }

  /**
   * Records reevaluation in the source transaction.
   */
  public function request(string $owner): void {
    $this->database->insert('task_dependency_request')->fields([
      'uuid' => $this->uuid->generate(),
      'owner' => $owner,
    ])->execute();
  }

  /**
   * Reserves a bounded batch of committed reevaluations for Queue API.
   */
  public function reserve(): array {
    $this->assertCommitted();
    $now = $this->time->getCurrentTime();
    $rows = $this->database->select('task_dependency_request', 'r')->fields('r')
      ->condition('expires', $now, '<=')->orderBy('expires')->range(0, 50)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $messages = [];
    foreach ($rows as $row) {
      if ($this->database->update('task_dependency_request')->fields(['expires' => $now + 300])
        ->condition('uuid', $row['uuid'])->condition('expires', $now, '<=')->execute()) {
        $messages[$row['owner']]['owner'] = $row['owner'];
        $messages[$row['owner']]['ids'][] = $row['uuid'];
      }
    }
    return array_values($messages);
  }

  /**
   * Checks for durable intent before executing a potentially duplicate message.
   */
  public function pending(array $message): bool {
    $this->assertCommitted();
    return (bool) $this->database->select('task_dependency_request', 'r')->condition('owner', $message['owner'])
      ->condition('uuid', $message['ids'], 'IN')->countQuery()->execute()->fetchField();
  }

  /**
   * Acknowledges only the exact requests processed by this message.
   */
  public function acknowledge(array $message): void {
    $this->database->delete('task_dependency_request')->condition('owner', $message['owner'])->condition('uuid', $message['ids'], 'IN')->execute();
  }

  /**
   * Records provenance without copying entity payloads or labels.
   */
  public function record(Dependency $dependency, string $operation, array $details = []): void {
    $this->database->insert('task_dependency_history')->fields([
      'uuid' => $this->uuid->generate(),
      'dependency' => $dependency->uuid(),
      'owner' => $dependency->get('owner')->value,
      'operation' => $operation,
      'created' => $this->time->getCurrentTime(),
      'actor' => (int) $this->account->id(),
      'details' => json_encode($details, JSON_THROW_ON_ERROR),
    ])->execute();
  }

  /**
   * Rejects replacement loops and bounds the length of a replacement chain.
   */
  public function assertReplacement(Dependency $dependency, string $target_uuid): void {
    $rows = $this->database->select('task_dependency_history', 'h')->fields('h', ['details'])
      ->condition('dependency', $dependency->uuid())->condition('operation', 'retargeted')->range(0, 65)->execute()->fetchCol();
    if (count($rows) >= 64) {
      throw new \InvalidArgumentException('The dependency replacement chain is too long.');
    }
    foreach ($rows as $details) {
      $entry = json_decode($details, TRUE, 512, JSON_THROW_ON_ERROR);
      if ($entry['from']['entity_uuid'] === $target_uuid || $entry['to']['entity_uuid'] === $target_uuid) {
        throw new \InvalidArgumentException('Dependency replacements must not contain a cycle.');
      }
    }
  }

  /**
   * Enforces the commit boundary for transport operations.
   */
  protected function assertCommitted(): void {
    if ($this->database->inTransaction()) {
      throw new \LogicException('Dependency reevaluation requires committed requests.');
    }
  }

}
