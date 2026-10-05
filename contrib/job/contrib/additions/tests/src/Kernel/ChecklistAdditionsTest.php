<?php

namespace Drupal\Tests\task_job_additions\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\task_job_additions\AdditionDefinitions;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tests additions through real job resolution and item operations.
 *
 * @group task_job_additions
 */
class ChecklistAdditionsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity',
    'task', 'task_context', 'task_checklist', 'task_job', 'checklist',
    'plugin_reference', 'typed_data', 'typed_data_reference',
    'typed_data_context_assignment', 'entity_template', 'typed_data_plus',
    'inline_entity_form', 'views', 'checklist_context_test',
    'checklist_state_test', 'task_job_additions',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('task_checklist', ['task_checklist_request']);
    $this->installSchema('task_job', ['task_job_trigger_index']);
    $this->installSchema('task_job_additions', ['task_job_checklist_addition']);
    $this->installSchema('checklist', ['checklist_attempt', 'checklist_attempt_head', 'checklist_attempt_event']);
    foreach (['user', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user']);
    User::create(['name' => 'Root', 'status' => 1])->save();
    Role::create([
      'id' => 'staff',
      'label' => 'Staff',
      'permissions' => ['view any tasks', 'update any tasks', 'add task checklist templates'],
    ])->save();
    $staff = User::create(['name' => 'Staff', 'status' => 1, 'roles' => ['staff']]);
    $staff->save();
    $this->container->get('current_user')->setAccount($staff);
  }

  /**
   * Creates optional work with an outcome consumed within each instance.
   */
  protected function work(): array {
    $job = Job::create([
      'id' => 'support',
      'label' => 'Support',
      'checklist_templates' => [
        'review' => [
          'label' => 'Review evidence',
          'allow_addition' => TRUE,
          'items' => [
            'decision' => [
              'label' => 'Approve evidence',
              'handler' => 'decision',
              'handler_configuration' => ['options' => ['yes' => ['label' => 'Accept'], 'no' => ['label' => 'Decline']]],
            ],
            'record' => [
              'label' => 'Record outcome',
              'handler' => 'context_consumer',
              'handler_configuration' => ['context_mapping' => ['value' => 'item:decision:decision']],
            ],
          ],
        ],
        'private' => ['label' => 'Internal only', 'items' => []],
      ],
    ]);
    $job->save();
    $task = Task::create(['title' => 'Support task', 'job' => $job, 'start' => '2000-01-01T00:00:00']);
    $task->save();
    return [$job, $task];
  }

  /**
   * Reloads the task and its definitions without a working graph cache.
   */
  protected function reload(Task $task): Task {
    return $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id());
  }

  /**
   * Repeated requests are idempotent; independent instances isolate data.
   */
  public function testIndependentInstancesAndIdempotency(): void {
    [, $task] = $this->work();
    $manager = $this->container->get('task_job_additions.manager');
    $this->assertSame(['review'], array_keys($manager->discover($task)['templates']));
    $first = $this->container->get('uuid')->generate();
    $second = $this->container->get('uuid')->generate();
    $receipt = $manager->add($task, 'review', $first);
    $this->assertEquals($receipt, $manager->add($task, 'review', $first));
    $manager->add($task, 'review', $second);
    $this->assertCount(2, $manager->discover($task)['additions']);
    $checklist = $this->reload($task)->checklist->checklist;
    $this->assertCount(4, $checklist->getItems());
    $this->assertFalse($checklist->isCompletable());
    foreach ($checklist->getItems() as $item) {
      $this->assertFalse($item->isNew());
    }
    $dispatcher = $this->container->get('checklist.action_operation_dispatcher');
    $a = AdditionDefinitions::prefix($first);
    $b = AdditionDefinitions::prefix($second);
    $dispatcher->execute($checklist, $a . 'decision', 'choose', ['choice' => 'yes']);
    $dispatcher->execute($checklist, $b . 'decision', 'choose', ['choice' => 'no']);
    $preparer = $this->container->get('checklist.context_preparer');
    $this->assertTrue($preparer->prepare($checklist, $checklist->getItem($a . 'record')));
    $this->assertTrue($preparer->prepare($checklist, $checklist->getItem($b . 'record')));
    $this->assertSame('yes', $checklist->getItem($a . 'record')->getHandler()->getContextValue('value'));
    $this->assertSame('no', $checklist->getItem($b . 'record')->getHandler()->getContextValue('value'));
    $request = $this->container->get('database')->select('task_checklist_request', 'r')->fields('r')->condition('task_uuid', $task->uuid())->execute()->fetchAssoc();
    $this->assertNotEmpty($request);
    $task->delete();
    $this->assertSame([], $this->container->get('task_job_additions.storage')->forTask($task->uuid()));
  }

  /**
   * Existing instances follow their pinned version and dirty fixes, not latest.
   */
  public function testVersionedDefinitionsAndRetainedReceipts(): void {
    [$job, $task] = $this->work();
    $versions = $this->container->get('task_job.version_resolver');
    $version = $versions->createVersion($job, '6');
    $version->save();
    $task->set('job_version', '6');
    $task->set('checklist', ['id' => 'job', 'configuration' => ['job' => 'support', 'job_version' => '6']]);
    $task->save();
    $manager = $this->container->get('task_job_additions.manager');
    $id = $this->container->get('uuid')->generate();
    $receipt = $manager->add($task, 'review', $id);
    $this->assertSame('6', $receipt['job_version']);
    $prefix = AdditionDefinitions::prefix($id);
    $checklist = $this->reload($task)->checklist->checklist;
    $decision = $checklist->getItem($prefix . 'decision');
    $decision->setOutcome('decision', 'yes')->setComplete();
    $decision->save();
    $uuid = $checklist->getItem($prefix . 'record')->uuid();
    $latest = $versions->createVersion($version, '7');
    $items = $latest->getChecklistItems('review');
    $items['record']['label'] = 'Wrong version';
    $latest->setChecklistItems($items, 'review');
    $latest->save();
    $this->assertSame('Record outcome', $this->reload($task)->checklist->checklist->getItem($prefix . 'record')->get('title')->value);
    $dirty = $versions->createDirtyVersion($version);
    $items['record']['label'] = 'Corrected outcome';
    $items['decision']['label'] = 'Changed decision';
    $dirty->setChecklistItems($items, 'review');
    $dirty->save();
    $checklist = $this->reload($task)->checklist->checklist;
    $this->assertSame($dirty->id(), $checklist->getType()->getJob()->id());
    $this->assertTrue($checklist->getItem($prefix . 'record')->isIncomplete());
    $this->assertSame('Corrected outcome', $checklist->getItem($prefix . 'record')->get('title')->value);
    $this->assertSame($uuid, $checklist->getItem($prefix . 'record')->uuid());
    $this->assertSame('Approve evidence', $checklist->getItem($prefix . 'decision')->get('title')->value);
    $dirty->set('checklist_templates', [])->save();
    $checklist = $this->reload($task)->checklist->checklist;
    $this->assertFalse($checklist->isItemActive($checklist->getItem($prefix . 'record')));
    $this->assertTrue($checklist->getItem($prefix . 'decision')->isComplete());
    $this->assertCount(1, $manager->discover($task)['additions']);
  }

  /**
   * Denied requests cannot persist work or manufacture an addition receipt.
   *
   * @dataProvider deniedRequests
   */
  public function testAccess(string $reason): void {
    [$job, $task] = $this->work();
    if ($reason === 'permission') {
      Role::load('staff')->revokePermission('add task checklist templates')->save();
    }
    elseif ($reason === 'task_access') {
      Role::load('staff')->revokePermission('update any tasks')->save();
    }
    elseif ($reason === 'completed') {
      $task->set('status', Task::STATUS_RESOLVED)->save();
    }
    elseif ($reason === 'blocked_user') {
      User::load($this->container->get('current_user')->id())->block()->save();
    }
    else {
      $templates = $job->get('checklist_templates');
      $templates['review']['allow_addition'] = FALSE;
      $job->set('checklist_templates', $templates)->save();
    }
    try {
      $this->container->get('task_job_additions.manager')->add($task, 'review', $this->container->get('uuid')->generate());
      $this->fail('Denied addition should throw.');
    }
    catch (AccessDeniedHttpException) {
      $this->assertSame([], $this->container->get('task_job_additions.storage')->forTask($task->uuid()));
    }
  }

  /**
   * Provides independent authorization failures.
   */
  public static function deniedRequests(): array {
    return [['permission'], ['task_access'], ['completed'], ['blocked_user'], ['template']];
  }

  /**
   * Request IDs cannot be borrowed by another task or another initiating user.
   */
  public function testUuidConflict(): void {
    [, $task] = $this->work();
    $manager = $this->container->get('task_job_additions.manager');
    $id = $this->container->get('uuid')->generate();
    $manager->add($task, 'review', $id);
    $other = Task::create(['title' => 'Other task', 'job' => 'support']);
    $other->save();
    $this->expectException(ConflictHttpException::class);
    $manager->add($other, 'review', $id);
  }

  /**
   * Added automatic work needs local approval and pins all three identities.
   */
  public function testDelegatedAdditionAuthorization(): void {
    [$job, $task] = $this->work();
    $caller = $this->container->get('current_user')->getAccount();
    $author = User::load(1);
    $worker = User::create(['name' => 'Worker', 'status' => 1, 'roles' => ['staff']]);
    $worker->save();
    $this->container->get('current_user')->setAccount($author);
    $templates = $job->get('checklist_templates');
    $templates['automatic'] = [
      'label' => 'Automatic work',
      'allow_addition' => TRUE,
      'items' => [
        'work' => [
          'label' => 'Record task',
          'handler' => 'iteration_test',
          'handler_configuration' => ['context_mapping' => ['value' => 'checklist:entity.title.value']],
          'execution' => ['mode' => 'context', 'context_mapping' => ['executor' => 'checklist:entity.assignee.entity']],
        ],
      ],
    ];
    $job->set('checklist_templates', $templates)->save();
    $task->set('assignee', $worker)->save();
    $this->container->get('current_user')->setAccount($caller);
    $manager = $this->container->get('task_job_additions.manager');
    $id = $this->container->get('uuid')->generate();
    $receipt = $manager->add($task, 'automatic', $id);
    $this->assertSame((int) $caller->id(), $receipt['actor']);
    $item = $this->reload($task)->checklist->checklist->getItem(AdditionDefinitions::prefix($id) . 'work');
    $attempt = $this->container->get('checklist.item_executor')->submit($item, TRUE);
    $this->assertSame((int) $caller->id(), $attempt->initiator);
    $this->assertSame((int) $worker->id(), $attempt->executor);
    $this->assertSame((int) $author->id(), $attempt->authorization['authorizer']);
    // An import cannot manufacture a local grant for future additions.
    $job->setSyncing(TRUE)->save();
    try {
      $manager->add($task, 'automatic', $this->container->get('uuid')->generate());
      $this->fail('Unapproved automatic additions must be denied.');
    }
    catch (AccessDeniedHttpException) {
      $this->assertCount(1, $manager->discover($task)['additions']);
    }
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('checklist.item_executor')->run($attempt);
  }

  /**
   * Invalid catalogs roll back receipts, items and processing requests.
   */
  public function testCollisionRollsBackAddition(): void {
    [$job, $task] = $this->work();
    $id = $this->container->get('uuid')->generate();
    $job->set('default_checklist', [
      AdditionDefinitions::prefix($id) . 'decision' => [
        'label' => 'Conflicting name',
        'handler' => 'simply_checkable',
        'handler_configuration' => [],
      ],
    ])->save();
    $requests = $this->container->get('task_checklist.request_storage');
    $requests->delete($task->uuid());
    try {
      $this->container->get('task_job_additions.manager')->add($task, 'review', $id);
      $this->fail('A conflicting namespace must be rejected.');
    }
    catch (\InvalidArgumentException) {
      $this->assertNull($this->container->get('task_job_additions.storage')->load($id));
      $this->assertSame([], $requests->reserve());
      $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('checklist_item')->getQuery()->accessCheck(FALSE)->count()->execute());
    }
  }

  /**
   * Nested branches activate; disabling additions retains existing work.
   */
  public function testNestedBranchAndDisabledTemplate(): void {
    [$job, $task] = $this->work();
    $templates = $job->get('checklist_templates');
    $templates['review']['items']['decision']['handler_configuration']['options']['yes']['template'] = 'follow_up';
    $templates['follow_up'] = [
      'label' => 'Follow up',
      'items' => [
        'confirm' => [
          'label' => 'Confirm follow up',
          'handler' => 'simply_checkable',
          'handler_configuration' => [],
        ],
      ],
    ];
    $job->set('checklist_templates', $templates)->save();
    $id = $this->container->get('uuid')->generate();
    $manager = $this->container->get('task_job_additions.manager');
    $manager->add($task, 'review', $id);
    $prefix = AdditionDefinitions::prefix($id);
    $checklist = $this->reload($task)->checklist->checklist;
    $child = $checklist->getItem($prefix . 'decision__yes__follow_up__confirm');
    $this->assertTrue($child->isNew());
    $this->assertFalse($child->isApplicable());
    $this->container->get('checklist.action_operation_dispatcher')->execute($checklist, $prefix . 'decision', 'choose', ['choice' => 'yes']);
    $this->assertTrue($child->isApplicable());
    $templates['review']['allow_addition'] = FALSE;
    $job->set('checklist_templates', $templates)->save();
    $checklist = $this->reload($task)->checklist->checklist;
    $this->assertTrue($checklist->getItem($child->getName())->isApplicable());
    $this->assertSame([], $manager->discover($task)['templates']);
    // Replay is harmless even after this template stops accepting new work.
    $this->assertSame($id, $manager->add($task, 'review', $id)['id']);
  }

}
