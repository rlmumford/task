<?php

namespace Drupal\Tests\task\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\user\Entity\User;

/**
 * Exercises scheduling, task chains, dependencies and access in real storage.
 *
 * @group task
 */
class TaskFoundationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity', 'task'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('task');
    $this->installConfig(['system', 'user']);
  }

  /**
   * Future work is released at its start time without releasing manual holds.
   */
  public function testSchedule(): void {
    $task = Task::create(['title' => 'Scheduled', 'start' => gmdate('Y-m-d\TH:i:s', time() + 3600)]);
    $task->save();
    $this->assertSame('pending', $task->status->value);
    $this->assertEquals($task->id(), $task->root->target_id);
    $task->start = '2000-01-01T12:00:00';
    $task->save();
    $this->assertSame('active', $task->status->value);
    $task->status = 'waiting';
    $task->save();
    $this->container->get('plugin.manager.queue_worker')->createInstance('task_scheduled')->processItem($task->id());
    $this->assertSame('waiting', Task::load($task->id())->status->value);
  }

  /**
   * A dependent task activates when its prerequisite resolves.
   */
  public function testDependencies(): void {
    $first = Task::create(['title' => 'First']);
    $first->save();
    $next = Task::create(['title' => 'Next', 'dependencies' => [$first], 'root' => $first]);
    $next->save();
    $this->assertSame('pending', $next->status->value);
    $first->resolve()->save();
    $this->container->get('entity_type.manager')->getStorage('task')->resetCache();
    $next = Task::load($next->id());
    $this->assertSame('active', $next->status->value);
    $this->assertEquals($first->id(), $next->root->target_id);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $first->resolved->value);
  }

  /**
   * Assigned-task access varies by user, including its cache metadata.
   */
  public function testAssignedAccess(): void {
    // Reserve uid 1 so the accounts under test do not bypass access checks.
    User::create(['name' => 'root'])->save();
    $role = $this->container->get('entity_type.manager')->getStorage('user_role')->create([
      'id' => 'worker',
      'label' => 'Worker',
      'permissions' => ['view assigned tasks'],
    ]);
    $role->save();
    $owner = User::create(['name' => 'owner', 'roles' => ['worker']]);
    $owner->save();
    $other = User::create(['name' => 'other', 'roles' => ['worker']]);
    $other->save();
    $task = Task::create(['title' => 'Private', 'assignee' => $owner]);
    $task->save();
    $allowed = $task->access('view', $owner, TRUE);
    $this->assertTrue($allowed->isAllowed());
    $this->assertContains('user', $allowed->getCacheContexts());
    $this->assertFalse($task->access('view', $other));
  }

  /**
   * Cron releases scheduled work and duplicate queue deliveries are harmless.
   */
  public function testScheduledQueue(): void {
    $time = $this->createMock(TimeInterface::class);
    $now = 1800000000;
    $time->method('getRequestTime')->willReturnCallback(function () use (&$now) {
      return $now;
    });
    $time->method('getCurrentTime')->willReturnCallback(function () use (&$now) {
      return $now;
    });
    $this->container->set('datetime.time', $time);
    $task = Task::create(['title' => 'Due later', 'start' => gmdate('Y-m-d\TH:i:s', $now + 60)]);
    $task->save();
    $this->assertSame('pending', $task->status->value);
    $now += 120;
    task_cron();
    $queue = $this->container->get('queue')->get('task_scheduled');
    $item = $queue->claimItem();
    $this->assertEquals($task->id(), $item->data);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('task_scheduled');
    $worker->processItem($item->data);
    $this->assertSame('active', Task::load($task->id())->status->value);
    Task::load($task->id())->resolve()->save();
    $worker->processItem($item->data);
    $this->assertSame('resolved', Task::load($task->id())->status->value);
  }

  /**
   * Cycles fail on save, before dependency propagation can recurse forever.
   */
  public function testDependencyCycle(): void {
    $first = Task::create(['title' => 'First']);
    $first->save();
    $second = Task::create(['title' => 'Second', 'dependencies' => [$first]]);
    $second->save();
    $first->dependencies = [$second];
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Task dependencies must not contain a cycle.');
    $first->save();
  }

}
