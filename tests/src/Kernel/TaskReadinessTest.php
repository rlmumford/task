<?php

namespace Drupal\Tests\task\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\service\Entity\Service;
use Drupal\service\Entity\ServiceType;
use Drupal\task\Entity\Task;
use Drupal\task\Event\TaskReadinessEvent;

/**
 * Tests readiness precedence and fresh, immediate references.
 *
 * @group task
 */
class TaskReadinessTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime',
    'entity', 'task', 'views', 'service', 'task_readiness_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('service', ['service_hierarchy_lock']);
    $this->container->get('database')->insert('service_hierarchy_lock')->fields(['id' => 1])->execute();
    foreach (['user', 'service', 'task'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user']);
    ServiceType::create(['id' => 'work', 'label' => 'Work'])->save();
  }

  /**
   * All reasons remain available even when a higher-priority state wins.
   */
  public function testPrecedenceAndReasons(): void {
    $service = Service::create(['type' => 'work']);
    $service->save();
    $dependency = Task::create(['title' => 'Prerequisite']);
    $dependency->save();
    $task = Task::create([
      'title' => 'Work',
      'status' => 'waiting',
      'service' => $service,
      'dependencies' => [$dependency],
      'start' => '2099-01-01T00:00:00',
    ]);
    $evaluator = $this->container->get('task.readiness');
    $result = $evaluator->evaluate($task);
    $this->assertSame('pending', $result->state);
    $this->assertSame(['future_start', 'dependency_unresolved', 'service_draft'], array_column($result->reasons, 'code'));
    $task->start = '2000-01-01T00:00:00';
    $service->set('status', 'active')->save();
    $this->assertSame('waiting', $evaluator->evaluate($task)->state);
    $task->status = 'resolved';
    $this->assertSame('resolved', $evaluator->evaluate($task)->state);
    $task->status = 'closed';
    $this->assertSame('closed', $evaluator->evaluate($task)->state);
  }

  /**
   * Only resolved satisfies dependencies, including on retained task objects.
   */
  public function testDependencies(): void {
    $dependency = Task::create(['title' => 'Prerequisite']);
    $dependency->save();
    $task = Task::create(['title' => 'Work', 'dependencies' => [$dependency]]);
    $task->save();
    $evaluator = $this->container->get('task.readiness');
    $this->assertSame('waiting', $evaluator->evaluate($task)->state);
    $dependency->set('status', 'closed')->save();
    $this->assertSame('waiting', $evaluator->evaluate($task)->state);
    $dependency->resolve()->save();
    $this->assertSame('active', $evaluator->evaluate($task)->state);
    $dependency->set('status', 'active')->save();
    $this->assertSame('waiting', $evaluator->evaluate($task)->state);
    $dependency->delete();
    $this->assertSame('dependency_missing', $evaluator->evaluate($task)->reasons[0]['code']);
  }

  /**
   * Tasks without a recorded service remain active and receive no service gate.
   */
  public function testTaskWithoutService(): void {
    $task = Task::create(['title' => 'Independent work']);
    $evaluator = $this->container->get('task.readiness');
    $this->assertSame('active', $evaluator->evaluate($task)->state);
    $this->assertSame([], $evaluator->evaluate($task)->reasons);
    $task->save();
    $task = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id());
    $this->assertSame('active', $task->status->value);
    $this->assertSame('active', $evaluator->evaluate($task)->state);
    $this->assertSame([], $evaluator->evaluate($task)->reasons);
    $task->set('service', ['target_id' => NULL]);
    $task->save();
    $this->assertSame('active', $evaluator->evaluate($task)->state);
    $this->assertSame([], $evaluator->evaluate($task)->reasons);
  }

  /**
   * Only the immediate service gates work, without requiring task saves.
   */
  public function testImmediateServiceOnly(): void {
    $parent = Service::create(['type' => 'work']);
    $parent->save();
    $child = Service::create(['type' => 'work', 'status' => 'active', 'service' => $parent]);
    $child->save();
    $task = Task::create(['title' => 'Work', 'service' => $child]);
    $task->save();
    $evaluator = $this->container->get('task.readiness');
    foreach (['draft', 'complete', 'cancelled', 'superseded'] as $status) {
      $parent->set('status', $status)->save();
      $this->assertSame('active', $evaluator->evaluate($task)->state);
      $child->set('status', $status)->save();
      $expected = match ($status) {
        'draft' => 'pending',
        'cancelled', 'superseded' => 'invalid',
        default => 'waiting',
      };
      $this->assertSame($expected, $evaluator->evaluate($task)->state);
      $child->set('status', 'active')->save();
    }
    $task->set('service', 999999);
    $this->assertSame('service_missing', $evaluator->evaluate($task)->reasons[0]['code']);
    $task->set('service', NULL);
    $this->assertSame('active', $evaluator->evaluate($task)->state);
  }

  /**
   * Start time gates execution; due dates and deadlines do not.
   */
  public function testTimeBoundary(): void {
    $now = 1800000000;
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(function () use (&$now) {
      return $now;
    });
    $this->container->set('datetime.time', $time);
    $task = Task::create([
      'title' => 'Work',
      'start' => gmdate('Y-m-d\TH:i:s', $now + 1),
      'due' => '2000-01-01T00:00:00',
      'deadline' => '2000-01-01T00:00:00',
    ]);
    $evaluator = $this->container->get('task.readiness');
    $this->assertSame('pending', $evaluator->evaluate($task)->state);
    $now++;
    $this->assertSame('active', $evaluator->evaluate($task)->state);
  }

  /**
   * Cron reconsiders waiting tasks when their immediate service activates.
   */
  public function testServiceProgressQueue(): void {
    $service = Service::create(['type' => 'work', 'status' => 'complete']);
    $service->save();
    $task = Task::create(['title' => 'Work', 'service' => $service]);
    $task->save();
    $this->assertSame('waiting', $task->status->value);
    $service->set('status', 'active')->save();
    task_cron();
    $item = $this->container->get('queue')->get('task_scheduled')->claimItem();
    $this->assertEquals($task->id(), $item->data);
    $this->container->get('plugin.manager.queue_worker')->createInstance('task_scheduled')->processItem($item->data);
    $this->assertSame('active', Task::load($task->id())->status->value);
  }

  /**
   * Cancelled and superseded services invalidate their immediate task work.
   */
  public function testTerminalServiceInvalidatesTask(): void {
    foreach (['cancelled', 'superseded'] as $status) {
      $service = Service::create(['type' => 'work', 'status' => $status]);
      $service->save();
      $task = Task::create(['title' => ucfirst($status), 'service' => $service]);

      $result = $this->container->get('task.readiness')->evaluate($task);
      $this->assertSame('invalid', $result->state);
      $this->assertSame('service_' . $status, $result->reasons[0]['code']);
    }
  }

  /**
   * Module gates are additive and obey the same precedence as core task gates.
   */
  public function testModuleContributions(): void {
    $dependency = Task::create(['title' => 'Prerequisite']);
    $dependency->save();
    $task = Task::create(['title' => 'Work', 'dependencies' => [$dependency]]);
    $evaluator = $this->container->get('task.readiness');
    $this->container->get('state')->set('task_readiness_test.reasons', [
      ['state' => 'waiting', 'code' => 'example_approval_required'],
      ['state' => 'pending', 'code' => 'example_not_started'],
    ]);
    $result = $evaluator->evaluate($task);
    $this->assertSame('pending', $result->state);
    $this->assertSame(['dependency_unresolved', 'example_approval_required', 'example_not_started'], array_column($result->reasons, 'code'));
    $task->status = 'resolved';
    $this->assertSame('resolved', $evaluator->evaluate($task)->state);
    $task->status = 'waiting';
    $this->container->get('state')->set('task_readiness_test.reasons', [
      ['state' => 'active', 'code' => 'example_ready'],
    ]);
    $this->assertSame('waiting', $evaluator->evaluate($task)->state);
    $dependency->resolve()->save();
    $this->assertSame('active', $evaluator->evaluate($task)->state);
  }

  /**
   * An invalid subscriber contribution cannot silently release blocked work.
   */
  public function testInvalidContribution(): void {
    $this->container->get('state')->set('task_readiness_test.reasons', [
      ['state' => 'unknown', 'code' => 'incorrect_override'],
    ]);
    $this->expectException(\UnexpectedValueException::class);
    $this->container->get('task.readiness')->evaluate(Task::create(['title' => 'Work']));
  }

  /**
   * Invalidation stays read-only until saving and preserves terminal outcomes.
   */
  public function testInvalidation(): void {
    $task = Task::create(['title' => 'Work', 'start' => '2099-01-01T00:00:00']);
    $task->save();
    $this->container->get('state')->set('task_readiness_test.reasons', [
      ['state' => 'invalid', 'code' => 'example_no_longer_required'],
    ]);
    $evaluator = $this->container->get('task.readiness');
    $this->assertSame('invalid', $evaluator->evaluate($task)->state);
    $this->assertSame('pending', Task::load($task->id())->status->value);
    $task->save();
    $this->assertSame('resolved', $task->status->value);
    $this->assertSame('invalid', $task->resolution->value);
    $this->assertNotEmpty($task->resolved->value);
    $task->resolve('complete')->save();
    $this->assertSame('resolved', $evaluator->evaluate($task)->state);
    $this->assertSame('complete', $task->resolution->value);
  }

  /**
   * Listener priority cannot allow an active decision to override a blocker.
   */
  public function testSubscriberOrder(): void {
    $task = Task::create(['title' => 'Work']);
    $dispatcher = $this->container->get('event_dispatcher');
    $ready = static function (TaskReadinessEvent $event): void {
      $event->addReason('active', 'example_ready');
    };
    $blocked = static function (TaskReadinessEvent $event): void {
      $event->addReason('waiting', 'example_approval_required');
    };
    $dispatcher->addListener(TaskReadinessEvent::class, $blocked);
    foreach ([-100, 100] as $priority) {
      $dispatcher->addListener(TaskReadinessEvent::class, $ready, $priority);
      $result = $this->container->get('task.readiness')->evaluate($task);
      $this->assertSame('waiting', $result->state);
      $this->assertCount(2, $result->reasons);
      $dispatcher->removeListener(TaskReadinessEvent::class, $ready);
    }
  }

  /**
   * Diagnostic values cannot replace a contribution's declared state or code.
   */
  public function testReasonIntegrity(): void {
    $event = new TaskReadinessEvent(Task::create(['title' => 'Work']));
    $event->addReason('waiting', 'example_approval_required', ['state' => 'active', 'code' => 'replacement']);
    $reasons = $event->getReasons();
    $this->assertSame('waiting', $reasons[0]['state']);
    $this->assertSame('example_approval_required', $reasons[0]['code']);
    $reasons[0]['state'] = 'active';
    $this->assertSame('waiting', $event->getReasons()[0]['state']);
  }

}
