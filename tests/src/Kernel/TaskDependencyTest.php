<?php

namespace Drupal\Tests\task\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_dependency\DependencyScheduler;
use Drupal\user\Entity\User;

/**
 * Tests event subscriptions, actions, identity and transactional delivery.
 *
 * @group task
 */
class TaskDependencyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'datetime',
    'entity',
    'task',
    'task_dependency',
    'entity_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user']);
    foreach (['user', 'task', 'task_dependency', 'entity_test'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('task_dependency', [
      'task_dependency_target_lock',
      'task_dependency_request',
      'task_dependency_history',
    ]);
    $account = User::create(['name' => 'Author', 'status' => 1]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * Drains committed dependency reevaluations without opening task pages.
   */
  protected function drain(): void {
    $scheduler = $this->container->get('task_dependency.scheduler');
    $scheduler->dispatch();
    $queue = $this->container->get('queue')->get(DependencyScheduler::QUEUE);
    while ($message = $queue->claimItem()) {
      $scheduler->run($message->data);
      $queue->deleteItem($message);
    }
  }

  /**
   * Loads current task state rather than a cached working form object.
   */
  protected function fresh(Task $task): Task {
    return $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id());
  }

  /**
   * Task resolution satisfies a UUID-backed subscription and activates work.
   */
  public function testTaskResolution(): void {
    $source = Task::create(['title' => 'Prerequisite']);
    $source->save();
    $task = Task::create(['title' => 'Work']);
    $dependency = $this->container->get('task_dependency.manager')->create($task, 'task.resolved', [], 'activate', $source);
    $this->assertNotEmpty($dependency->id());
    $this->assertSame($dependency->uuid(), $dependency->id());
    $task->event_dependencies[] = ['entity' => $dependency];
    $task->save();
    $this->assertSame('waiting', $task->status->value);
    $this->assertFalse($dependency->isNew());
    $source->resolve()->save();
    $this->assertSame('waiting', $this->fresh($task)->status->value);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->drain();
    $this->assertSame('active', $this->fresh($task)->status->value);
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
  }

  /**
   * Approval is remembered even if it reverses before the worker runs.
   */
  public function testDocumentApprovalAndInvalidation(): void {
    $document = EntityTest::create(['name' => 'draft']);
    $document->save();
    $task = Task::create(['title' => 'Review approved document']);
    $manager = $this->container->get('task_dependency.manager');
    foreach (['activate' => 'approved', 'invalidate' => 'cancelled'] as $action => $value) {
      $task->event_dependencies[] = [
        'entity' => $manager->create($task, 'entity.state:entity_test', [
          'field' => 'name',
          'value' => $value,
        ], $action, $document),
      ];
    }
    $task->save();
    $document->set('name', 'approved')->save();
    $document->set('name', 'draft')->save();
    $this->drain();
    $this->assertSame('active', $this->fresh($task)->status->value);
    $document->set('name', 'cancelled')->save();
    $this->drain();
    $saved = $this->fresh($task);
    $this->assertSame('resolved', $saved->status->value);
    $this->assertSame('invalid', $saved->resolution->value);
  }

  /**
   * No unrelated document or unchanged qualifying save creates a match.
   */
  public function testExactBindingAndRegistration(): void {
    $document = EntityTest::create(['name' => 'approved']);
    $document->save();
    $other = EntityTest::create(['name' => 'draft']);
    $other->save();
    $task = Task::create(['title' => 'Await a future approval']);
    $task->event_dependencies[] = [
      'entity' => $this->container->get('task_dependency.manager')->create($task, 'entity.state:entity_test', [
        'field' => 'name',
        'value' => 'approved',
      ], 'activate', $document),
    ];
    $task->save();
    $document->save();
    $other->set('name', 'approved')->save();
    $this->drain();
    $this->assertSame('waiting', $this->fresh($task)->status->value);
    $document->set('name', 'draft')->save();
    $document->set('name', 'approved')->save();
    $this->drain();
    $this->assertSame('active', $this->fresh($task)->status->value);
  }

  /**
   * Rollback removes the occurrence receipt and its reevaluation request.
   */
  public function testRollback(): void {
    $source = Task::create(['title' => 'Prerequisite']);
    $source->save();
    $task = Task::create(['title' => 'Work']);
    $task->event_dependencies[] = ['entity' => $this->container->get('task_dependency.manager')->create($task, 'task.resolved', [], 'activate', $source)];
    $task->save();
    $this->drain();
    $transaction = $this->container->get('database')->startTransaction();
    $source->resolve()->save();
    $transaction->rollBack();
    unset($transaction);
    $this->drain();
    $this->assertSame('waiting', $this->fresh($task)->status->value);
    $dependency = $this->fresh($task)->event_dependencies->entity;
    $this->assertFalse((bool) $dependency->met->value);
  }

  /**
   * Replacement following changes the exact binding and rejects late events.
   */
  public function testReplacement(): void {
    $first = EntityTest::create(['name' => 'scheduled']);
    $first->save();
    $next = EntityTest::create(['name' => 'scheduled']);
    $next->save();
    $task = Task::create(['title' => 'After attendance']);
    $manager = $this->container->get('task_dependency.manager');
    $task->event_dependencies[] = [
      'entity' => $manager->create($task, 'entity.state:entity_test', [
        'field' => 'name',
        'value' => 'attended',
      ], 'activate', $first),
    ];
    $task->save();
    $unchanged = Task::create(['title' => 'Stay with the original event']);
    $unchanged->event_dependencies[] = [
      'entity' => $manager->create($unchanged, 'entity.state:entity_test', [
        'field' => 'name',
        'value' => 'attended',
      ], 'activate', $first),
    ];
    $unchanged->save();
    $this->assertSame(0, $manager->retarget($first, $next, []));
    $this->assertSame(1, $manager->retarget($first, $next, [$task->event_dependencies->target_id]));
    $first->set('name', 'attended')->save();
    $this->drain();
    $this->assertSame('waiting', $this->fresh($task)->status->value);
    $this->assertSame('active', $this->fresh($unchanged)->status->value);
    $next->set('name', 'attended')->save();
    $this->drain();
    $this->assertSame('active', $this->fresh($task)->status->value);
  }

  /**
   * Invalidation wins over unmet gates and never rewrites a terminal result.
   */
  public function testInvalidationPrecedenceAndTerminalState(): void {
    $first = Task::create(['title' => 'Activation gate']);
    $first->save();
    $cancel = Task::create(['title' => 'Cancellation signal']);
    $cancel->save();
    $task = Task::create(['title' => 'Work']);
    $manager = $this->container->get('task_dependency.manager');
    $task->event_dependencies[] = ['entity' => $manager->create($task, 'task.resolved', [], 'activate', $first)];
    $task->event_dependencies[] = ['entity' => $manager->create($task, 'task.resolved', [], 'invalidate', $cancel)];
    $task->save();
    $cancel->resolve()->save();
    $this->drain();
    $saved = $this->fresh($task);
    $this->assertSame('invalid', $saved->resolution->value);
    $when = $saved->resolved->value;
    $first->resolve()->save();
    $this->drain();
    $this->assertSame('invalid', $this->fresh($task)->resolution->value);
    $this->assertSame($when, $this->fresh($task)->resolved->value);
  }

  /**
   * Editing unchanged definitions preserves UUIDs and system-owned receipts.
   */
  public function testEditorPreservesReceipts(): void {
    $source = Task::create(['title' => 'Prerequisite']);
    $source->save();
    $task = Task::create(['title' => 'Work']);
    $task->event_dependencies[] = ['entity' => $this->container->get('task_dependency.manager')->create($task, 'task.resolved', [], 'activate', $source)];
    $task->save();
    $source->resolve()->save();
    $this->container->get('entity_type.manager')->getStorage('task_dependency')->resetCache();
    $task = $this->fresh($task);
    $editor = $this->container->get('task_dependency.editor');
    $rows = $editor->values($task);
    $id = $rows[0]['id'];
    $rows[0] = array_reverse($rows[0], TRUE);
    $task->set('event_dependencies', $editor->prepare($task, $rows))->save();
    $dependency = $this->fresh($task)->event_dependencies->entity;
    $this->assertSame($id, $dependency->id());
    $this->assertTrue((bool) $dependency->met->value);
    $rows[0]['met'] = FALSE;
    $this->expectException(\InvalidArgumentException::class);
    $editor->prepare($task, $rows);
  }

  /**
   * Direct task cycles are rejected atomically, including the staged record.
   */
  public function testCycles(): void {
    $first = Task::create(['title' => 'First']);
    $first->save();
    $second = Task::create(['title' => 'Second']);
    $second->save();
    $manager = $this->container->get('task_dependency.manager');
    $second->event_dependencies[] = ['entity' => $manager->create($second, 'task.resolved', [], 'activate', $first)];
    $second->save();
    $first->event_dependencies[] = ['entity' => $manager->create($first, 'task.resolved', [], 'activate', $second)];
    try {
      $first->save();
      $this->fail('A task cycle must not be persisted.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('cycle', $exception->getMessage());
    }
    $this->assertTrue($this->fresh($first)->event_dependencies->isEmpty());
  }

  /**
   * Replacement cycles cannot silently revive an earlier target.
   */
  public function testReplacementCycle(): void {
    $first = EntityTest::create(['name' => 'scheduled']);
    $first->save();
    $next = EntityTest::create(['name' => 'scheduled']);
    $next->save();
    $task = Task::create(['title' => 'After attendance']);
    $manager = $this->container->get('task_dependency.manager');
    $task->event_dependencies[] = [
      'entity' => $manager->create($task, 'entity.state:entity_test', [
        'field' => 'name',
        'value' => 'attended',
      ], 'activate', $first),
    ];
    $task->save();
    $manager->retarget($first, $next, [$task->event_dependencies->target_id]);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('cycle');
    $manager->retarget($next, $first, [$task->event_dependencies->target_id]);
  }

  /**
   * Legacy edges cannot close a cycle through an event dependency.
   */
  public function testMixedCycle(): void {
    $first = Task::create(['title' => 'First']);
    $first->save();
    $second = Task::create(['title' => 'Second']);
    $second->event_dependencies[] = ['entity' => $this->container->get('task_dependency.manager')->create($second, 'task.resolved', [], 'activate', $first)];
    $second->save();
    $first->set('dependencies', [$second]);
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('cycle');
    $first->save();
  }

  /**
   * A resolved replacement preserves the terminal task-resolution convention.
   */
  public function testResolvedReplacement(): void {
    $first = Task::create(['title' => 'First']);
    $first->save();
    $replacement = Task::create(['title' => 'Replacement']);
    $replacement->resolve()->save();
    $task = Task::create(['title' => 'Follow up']);
    $manager = $this->container->get('task_dependency.manager');
    $task->event_dependencies[] = ['entity' => $manager->create($task, 'task.resolved', [], 'activate', $first)];
    $task->save();
    $manager->retarget($first, $replacement, [$task->event_dependencies->target_id]);
    $this->drain();
    $this->assertSame('active', $this->fresh($task)->status->value);
  }

  /**
   * Direct record access respects ownership and never permits receipt edits.
   */
  public function testDependencyAccess(): void {
    $source = Task::create(['title' => 'Prerequisite']);
    $source->save();
    $task = Task::create(['title' => 'Work']);
    $dependency = $this->container->get('task_dependency.manager')->create($task, 'task.resolved', [], 'activate', $source);
    $task->event_dependencies[] = ['entity' => $dependency];
    $task->save();
    $access = $dependency->access('view', NULL, TRUE);
    $this->assertTrue($access->isAllowed());
    $this->assertContains('task:' . $task->id(), $access->getCacheTags());
    $this->assertContains('task:' . $source->id(), $access->getCacheTags());
    $this->assertFalse($dependency->access('update'));
    $this->assertFalse($dependency->access('delete'));
    $this->assertFalse($dependency->access('view', new AnonymousUserSession()));
  }

}
