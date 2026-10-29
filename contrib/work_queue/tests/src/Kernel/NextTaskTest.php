<?php

namespace Drupal\Tests\task_work_queue\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task\TaskReadiness;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Exercises live recommendations independently of saved status and list pages.
 *
 * @group task_work_queue
 */
class NextTaskTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity', 'views',
    'task', 'task_ui', 'task_work_queue', 'task_checklist', 'checklist', 'plugin_reference',
    'typed_data', 'typed_data_plus', 'typed_data_reference',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('task_checklist', ['task_checklist_request']);
    foreach (['user', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user', 'task_ui', 'task_work_queue']);
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    Role::create([
      'id' => 'worker',
      'label' => 'Worker',
      'permissions' => ['view any tasks', 'update assigned tasks'],
    ])->save();
    $user = User::create(['name' => 'worker', 'roles' => ['worker'], 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturn(2000000000);
    $clock->method('getRequestTime')->willReturn(2000000000);
    $this->container->set('datetime.time', $clock);
    $this->container->set('task.readiness', new TaskReadiness(
      $this->container->get('entity_type.manager'), $clock, $this->container->get('event_dispatcher'),
    ));
  }

  /**
   * Creates assigned work using the test clock unless explicitly overridden.
   */
  private function task(array $values = []): Task {
    $task = Task::create($values + [
      'title' => 'Work',
      'assignee' => $this->container->get('current_user')->id(),
      'start' => gmdate('Y-m-d\TH:i:s', 2000000000),
    ]);
    $task->save();
    return $task;
  }

  /**
   * Ranking covers the whole queue and promotes actionable interruptions.
   */
  public function testOrderingAndLiveReadiness(): void {
    $urgent = $this->task(['due' => gmdate('Y-m-d\TH:i:s', 2000000000)]);
    for ($i = 0; $i < 30; $i++) {
      $this->task();
    }
    $selector = $this->container->get('task_work_queue.selector');
    $this->assertEquals($urgent->id(), $selector->select()->task->id());
    $this->assertSame('plan', $selector->select()->queue);
    $interrupt = $this->task(['work_interrupter' => TRUE]);
    $this->assertEquals($interrupt->id(), $selector->select()->task->id());
    $this->assertSame('interrupter', $selector->select()->queue);
    $this->task(['work_interrupter' => TRUE, 'start' => gmdate('Y-m-d\TH:i:s', 2000003600)]);
    $this->task(['work_interrupter' => TRUE, 'assignee' => 1]);
    $blocked = $this->task([
      'work_interrupter' => TRUE,
      'start' => gmdate('Y-m-d\TH:i:s', 1999990000),
      'dependencies' => [$urgent->id()],
    ]);
    $this->assertEquals($interrupt->id(), $selector->select()->task->id());
    $urgent->resolve()->save();
    // Simulate a stale stored status after a dependency has completed.
    $this->container->get('database')->update('task')->fields(['status' => 'waiting'])->condition('id', $blocked->id())->execute();
    $this->assertSame('waiting', $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($blocked->id())->get('status')->value);
    $this->assertEquals($blocked->id(), $selector->select()->task->id());
    // Selection does not claim, update readiness, or complete the task.
    $this->assertSame('waiting', $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($blocked->id())->get('status')->value);
    $blocked->resolve()->save();
    $interrupt->resolve()->save();
    $deadline = $this->task([
      'deadline' => gmdate('Y-m-d\TH:i:s', 2000001200),
      'work_estimate' => 300,
    ]);
    $this->assertEquals($deadline->id(), $selector->select()->task->id());
    $this->assertSame('interrupter', $selector->select()->queue);
    $this->assertNotEquals($deadline->id(), $selector->select((int) $deadline->id())->task->id());
  }

  /**
   * Work blocked by a prerequisite raises that prerequisite's Plan urgency.
   */
  public function testDependenciesAndLimit(): void {
    $ordinary = $this->task(['work_priority' => 'high']);
    $prerequisite = $this->task(['work_priority' => 'low']);
    $this->task([
      'dependencies' => [$prerequisite->id()],
      'assignee' => 1,
      'due' => gmdate('Y-m-d\TH:i:s', 2000000000),
    ]);
    $selector = $this->container->get('task_work_queue.selector');
    $this->assertEquals($prerequisite->id(), $selector->select()->task->id());
    $this->config('task_work_queue.settings')->set('maximum_tasks', 2)->save();
    $limited = $selector->select();
    $this->assertTrue($limited->limited);
    $this->assertNull($limited->task);
    $this->config('task_work_queue.settings')->set('maximum_tasks', 1)->save();
    $this->assertTrue($selector->select()->limited);
    $this->config('task_work_queue.settings')->set('maximum_tasks', 5000)->save();
    $prerequisite->resolve()->save();
    $this->assertEquals($ordinary->id(), $selector->select()->task->id());
  }

  /**
   * Read access alone cannot recommend a task for manual work.
   */
  public function testReadOnlyAssignee(): void {
    Role::create(['id' => 'reader', 'label' => 'Reader', 'permissions' => ['view any tasks']])->save();
    $reader = User::create(['name' => 'reader', 'roles' => ['reader'], 'status' => 1]);
    $reader->save();
    $this->task(['assignee' => $reader]);
    $this->container->get('current_user')->setAccount($reader);
    $result = $this->container->get('task_work_queue.selector')->select();
    $this->assertNull($result->task);
    $this->assertFalse($result->limited);
  }

}
