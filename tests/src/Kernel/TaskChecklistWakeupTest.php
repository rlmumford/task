<?php

namespace Drupal\Tests\task\Kernel;

use Drupal\checklist\Attempt\ChecklistAttempt;
use Drupal\checklist\Execution\ChecklistItemIterationScheduler;
use Drupal\checklist_state_test\Plugin\ChecklistItemHandler\SingleStep;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\task_checklist\TaskChecklistScheduler;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;

/**
 * Tests committed task saves and item outcomes waking whole checklists.
 *
 * @group task
 */
class TaskChecklistWakeupTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity',
    'task', 'task_context', 'task_checklist', 'task_job', 'checklist',
    'plugin_reference', 'typed_data', 'typed_data_reference',
    'typed_data_context_assignment', 'entity_template', 'typed_data_plus',
    'inline_entity_form', 'views', 'checklist_state_test',
  ];

  /**
   * The controlled scheduler clock.
   *
   * @var int
   */
  protected int $now;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('task_checklist', ['task_checklist_request']);
    $this->installSchema('task_job', ['task_job_trigger_index']);
    $this->installSchema('checklist', ['checklist_attempt', 'checklist_attempt_head', 'checklist_attempt_event']);
    foreach (['user', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user']);
    $account = User::create(['name' => 'Author', 'status' => 1]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
    $this->now = time();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn() => $this->now);
    $time->method('getRequestTime')->willReturnCallback(fn() => $this->now);
    $time->method('getCurrentMicroTime')->willReturnCallback(fn() => (float) $this->now);
    $this->container->set('datetime.time', $time);
    SingleStep::$calls = [];
    SingleStep::$during = NULL;
  }

  /**
   * Creates an active task with one automatic item, or a background chain.
   */
  protected function task(bool $chain = FALSE, array $values = []): Task {
    $items = [
      'source' => [
        'label' => 'Source',
        'handler' => $chain ? 'background_step_test' : 'single_step_test',
        'handler_configuration' => ['context_mapping' => ['value' => 'checklist:entity.title.value']],
      ],
    ];
    if ($chain) {
      $items['consumer'] = [
        'label' => 'Consumer',
        'handler' => 'single_step_test',
        'handler_configuration' => ['context_mapping' => ['value' => 'item:source:result']],
      ];
    }
    $job = Job::create(['id' => 'work_' . count(Job::loadMultiple()), 'label' => 'Work', 'default_checklist' => $items]);
    $job->save();
    $task = Task::create($values + ['title' => 'Work', 'job' => $job, 'start' => '2000-01-01T00:00:00']);
    $task->save();
    return $task;
  }

  /**
   * Drains checklist requests and their follow-up result changes.
   */
  protected function drain(): void {
    $scheduler = $this->container->get('task_checklist.scheduler');
    $queue = $this->container->get('queue')->get(TaskChecklistScheduler::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(TaskChecklistScheduler::QUEUE);
    for ($pass = 0; $pass < 5; $pass++) {
      $scheduler->dispatch();
      $worked = FALSE;
      while ($message = $queue->claimItem()) {
        $worker->processItem($message->data);
        $queue->deleteItem($message);
        $worked = TRUE;
      }
      if (!$worked) {
        return;
      }
    }
    $this->fail('Checklist wake-ups must settle without a save loop.');
  }

  /**
   * Repeated saves coalesce, and short work runs once after the save commits.
   */
  public function testActiveSave(): void {
    $task = $this->task();
    $task->save();
    $task->save();
    $this->assertSame([], SingleStep::$calls);
    $this->assertSame('active', Task::load($task->id())->status->value);
    $this->assertSame(1, $this->container->get('task_checklist.scheduler')->dispatch());
    $this->drain();
    $this->assertSame(['source'], SingleStep::$calls);
    $this->assertSame('resolved', Task::load($task->id())->status->value);
    $this->assertSame(0, $this->container->get('task_checklist.scheduler')->dispatch());
  }

  /**
   * A background outcome wakes and completes its downstream item without a UI.
   */
  public function testOutcomeWakeup(): void {
    $task = $this->task(TRUE);
    $this->drain();
    $this->assertSame([], SingleStep::$calls);
    $this->assertSame(1, $this->container->get('checklist.item_iteration_scheduler')->dispatch());
    $queue = $this->container->get('queue')->get(ChecklistItemIterationScheduler::QUEUE);
    $message = $queue->claimItem();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(ChecklistItemIterationScheduler::QUEUE);
    $worker->processItem($message->data);
    $queue->deleteItem($message);
    $this->assertSame(['source'], SingleStep::$calls);
    $this->drain();
    $this->assertSame(['source', 'consumer'], SingleStep::$calls);
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
    $saved = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id());
    $this->assertSame('resolved', $saved->status->value);
    $consumer = $saved->checklist->checklist->getItem('consumer');
    $this->assertSame('Work-done-done', $consumer->get('outcomes')->get('result')->getValue());
    $attempt = $this->container->get('checklist.attempt_journal')->latest($consumer);
    $this->assertSame(ChecklistAttempt::SUCCEEDED, $attempt->status);
    $this->assertSame(1, $attempt->executor);
  }

  /**
   * Rolled-back saves never deliver work, even with a non-SQL queue backend.
   */
  public function testRollback(): void {
    $transaction = $this->container->get('database')->startTransaction();
    $this->task();
    try {
      $this->container->get('task_checklist.scheduler')->dispatch();
      $this->fail('Uncommitted requests must not dispatch.');
    }
    catch (\LogicException) {
      $this->assertSame([], SingleStep::$calls);
    }
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame(0, $this->container->get('task_checklist.scheduler')->dispatch());
  }

  /**
   * Lost deliveries are recovered and an older acknowledgment preserves saves.
   */
  public function testDeliveryRecovery(): void {
    $task = $this->task(TRUE);
    $storage = $this->container->get('task_checklist.request_storage');
    $first = $storage->reserve()[0];
    $this->assertSame([], $storage->reserve());
    $this->now += 301;
    $this->assertCount(1, $storage->reserve());
    $task->save();
    $storage->acknowledge($first);
    $this->assertCount(1, $storage->reserve());
    $this->assertFalse($storage->pending($first));
  }

  /**
   * A lower request ID becoming visible later is not acknowledged accidentally.
   */
  public function testOutOfOrderVisibility(): void {
    $task = $this->task();
    $storage = $this->container->get('task_checklist.request_storage');
    $storage->delete($task->uuid());
    $database = $this->container->get('database');
    $row = ['task_id' => $task->id(), 'task_uuid' => $task->uuid(), 'executor' => 1];
    $database->insert('task_checklist_request')->fields(['id' => 100] + $row)->execute();
    $message = $storage->reserve()[0];
    // Model a transaction with a lower serial ID committing after reservation.
    $database->insert('task_checklist_request')->fields(['id' => 50] + $row)->execute();
    $storage->acknowledge($message);
    $remaining = $storage->reserve();
    $this->assertCount(1, $remaining);
    $this->assertSame([50], array_map('intval', $remaining[0]['request_ids']));
  }

  /**
   * Readiness and deactivated execution accounts are rechecked after enqueue.
   */
  public function testReadinessAndIdentity(): void {
    $task = $this->task();
    $storage = $this->container->get('task_checklist.request_storage');
    $message = $storage->reserve()[0];
    $task->start = '2099-01-01T00:00:00';
    $task->save();
    $this->container->get('task_checklist.request_runner')->run($message);
    $this->assertSame([], SingleStep::$calls);
    $task->start = '2000-01-01T00:00:00';
    $task->save();
    User::load(1)->block()->save();
    $this->drain();
    $this->assertSame([], SingleStep::$calls);
    $this->assertSame(0, $this->container->get('task_checklist.scheduler')->dispatch());
  }

  /**
   * Resolving a prerequisite schedules the dependent task without opening it.
   */
  public function testDependencyWakeup(): void {
    $blocker = Task::create(['title' => 'Prerequisite']);
    $blocker->save();
    $task = $this->task(values: ['dependencies' => [$blocker]]);
    $this->drain();
    $this->assertSame([], SingleStep::$calls);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $blocker->resolve()->save();
    $this->drain();
    $this->assertSame(['source'], SingleStep::$calls);
    $this->assertSame('resolved', Task::load($task->id())->status->value);
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
  }

  /**
   * A failed handler is audited once; delivery retries restore the account.
   */
  public function testFailureAndRedelivery(): void {
    $task = $this->task();
    $storage = $this->container->get('task_checklist.request_storage');
    $message = $storage->reserve()[0];
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    SingleStep::$during = static function (): void {
      throw new \RuntimeException('Provider failure');
    };
    $runner = $this->container->get('task_checklist.request_runner');
    try {
      $runner->run($message);
      $this->fail('Unexpected errors must propagate for delivery retry.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Provider failure', $exception->getMessage());
    }
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
    $this->assertTrue($storage->pending($message));
    $runner->run($message);
    $runner->run($message);
    $this->drain();
    $this->assertSame(['source'], SingleStep::$calls);
    $this->assertFalse($storage->pending($message));
    $this->assertSame('active', Task::load($task->id())->status->value);
  }

  /**
   * Revoking a role permission after enqueue prevents execution.
   */
  public function testPermissionRevocation(): void {
    $role = Role::create(['id' => 'worker', 'label' => 'Worker']);
    $role->grantPermission('view any tasks')->grantPermission('update any tasks')->save();
    $account = User::create(['name' => 'Worker', 'status' => 1, 'roles' => ['worker']]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
    $task = $this->task();
    $this->assertTrue($task->access('update'));
    $role->revokePermission('update any tasks')->save();
    $this->drain();
    $this->assertSame([], SingleStep::$calls);
    $role->grantPermission('update any tasks')->save();
    $task->save();
    $this->drain();
    $this->assertSame(['source'], SingleStep::$calls);
  }

  /**
   * The update installs the same storage schema and is safe to repeat.
   */
  public function testSchemaUpgrade(): void {
    $this->container->get('database')->schema()->dropTable('task_checklist_request');
    $this->container->get('module_handler')->loadInclude('task_checklist', 'install');
    task_checklist_update_10001();
    task_checklist_update_10001();
    $this->task();
    $this->assertSame(1, $this->container->get('task_checklist.scheduler')->dispatch());
  }

}
