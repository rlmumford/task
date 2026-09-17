<?php

namespace Drupal\Tests\task\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;

/**
 * Tests resolution timestamps across helper, direct and hook-driven writes.
 *
 * @group task
 */
class TaskResolutionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity',
    'task', 'task_resolution_test',
  ];

  /**
   * The simulated current time, distinct from the request start time.
   *
   * @var int
   */
  protected int $now = 1800000000;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('task');
    $this->installConfig(['system', 'user']);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($this->now - 3600);
    $time->method('getCurrentTime')->willReturnCallback(fn() => $this->now);
    $this->container->set('datetime.time', $time);
  }

  /**
   * Direct saves timestamp resolution, preserve retries and clear on reopening.
   */
  public function testDirectSaves(): void {
    $task = Task::create(['title' => 'Work', 'status' => 'resolved']);
    $task->save();
    $first = gmdate('Y-m-d\TH:i:s', $this->now);
    $this->assertSame($first, Task::load($task->id())->resolved->value);
    $this->now += 300;
    $task->save();
    $task->resolve()->save();
    $this->assertSame($first, Task::load($task->id())->resolved->value);
    $task->set('status', 'closed')->save();
    $this->assertSame($first, Task::load($task->id())->resolved->value);
    $task->set('status', 'active')->save();
    $this->assertTrue(Task::load($task->id())->resolved->isEmpty());
    $task->set('status', 'resolved')->save();
    $this->assertSame(gmdate('Y-m-d\TH:i:s', $this->now), Task::load($task->id())->resolved->value);
    $closed = Task::create(['title' => 'Closed without resolution', 'status' => 'closed']);
    $closed->save();
    $this->assertTrue($closed->resolved->isEmpty());
  }

  /**
   * Explicit times are normalized to UTC without changing the caller's object.
   */
  public function testExplicitTime(): void {
    $time = new \DateTime('2026-07-01 12:34:56', new \DateTimeZone('Europe/London'));
    $task = Task::create(['title' => 'Work']);
    $task->resolve('complete', $time)->save();
    $this->assertSame('2026-07-01T11:34:56', Task::load($task->id())->resolved->value);
    $this->assertSame('2026-07-01 12:34:56', $time->format('Y-m-d H:i:s'));
    $this->assertSame('Europe/London', $time->getTimezone()->getName());
    $task->resolve()->save();
    $this->assertSame('2026-07-01T11:34:56', $task->resolved->value);
  }

  /**
   * Final statuses set by presave hooks also control timestamp normalization.
   */
  public function testPresaveStatusChanges(): void {
    $task = Task::create(['title' => 'Work']);
    $task->save();
    $this->container->get('state')->set('task_resolution_test.status', 'resolved');
    $task->save();
    $this->assertSame(gmdate('Y-m-d\TH:i:s', $this->now), Task::load($task->id())->resolved->value);
    $this->container->get('state')->set('task_resolution_test.status', 'pending');
    $task->save();
    $this->assertTrue(Task::load($task->id())->resolved->isEmpty());
  }

}
