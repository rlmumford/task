<?php

namespace Drupal\Tests\task\Kernel;

use Drupal\checklist\Attempt\ChecklistAttempt;
use Drupal\checklist\Entity\ChecklistItem;
use Drupal\checklist_state_test\Plugin\ChecklistItemHandler\Iteration;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\task_job\JobExecutionAuthorization;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Delegation is authorized by saved job policy, not caller-controlled settings.
 *
 * @group task
 */
class JobExecutionAuthorizationTest extends KernelTestBase {

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
   * Controlled current time.
   *
   * @var int
   */
  protected int $now = 2000000000;
  /**
   * The job authorizer.
   *
   * @var \Drupal\user\Entity\User
   */
  protected User $author;
  /**
   * The initiating staff account.
   *
   * @var \Drupal\user\Entity\User
   */
  protected User $caller;
  /**
   * The selected execution account.
   *
   * @var \Drupal\user\Entity\User
   */
  protected User $executorAccount;

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
    User::create(['name' => 'Root', 'status' => 1])->save();
    Role::create([
      'id' => 'work',
      'label' => 'Work',
      'permissions' => ['view any tasks', 'update any tasks', 'administer task jobs'],
    ])->save();
    Role::create([
      'id' => 'approve',
      'label' => 'Approve',
      'permissions' => [JobExecutionAuthorization::PERMISSION],
    ])->save();
    $this->author = $this->account('Configurer', ['work', 'approve']);
    $this->caller = $this->account('Initiator', ['work']);
    $this->executorAccount = $this->account('Worker', ['work']);
    $this->container->get('current_user')->setAccount($this->author);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn() => $this->now);
    $time->method('getRequestTime')->willReturnCallback(fn() => $this->now);
    $this->container->set('datetime.time', $time);
    Iteration::$calls = [];
    Iteration::$during = NULL;
  }

  /**
   * Creates an active test account with the specified roles.
   */
  protected function account(string $name, array $roles): User {
    $account = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $account->save();
    return $account;
  }

  /**
   * Saves approved work and returns it under the initiating account.
   */
  protected function work(): array {
    $job = Job::create([
      'id' => 'delegated',
      'label' => 'Delegated work',
      'default_checklist' => [
        'work' => [
          'label' => 'Run as assignee',
          'handler' => 'iteration_test',
          'handler_configuration' => ['context_mapping' => ['value' => 'checklist:entity.title.value']],
          'execution' => ['mode' => 'context', 'context_mapping' => ['executor' => 'checklist:entity.assignee.entity']],
        ],
      ],
    ]);
    $job->save();
    $task = Task::create([
      'title' => 'Test work',
      'job' => $job,
      'assignee' => $this->executorAccount,
      'start' => '2000-01-01T00:00:00',
    ]);
    $task->save();
    $item = $task->checklist->checklist->getItem('work');
    $item->save();
    $this->container->get('current_user')->setAccount($this->caller);
    return [$job, $task, $item];
  }

  /**
   * Records all identities and pins execution across reassignment.
   */
  public function testDelegationPinsIdentityAndRecordsAuthority(): void {
    [$job, $task, $item] = $this->work();
    $runner = $this->container->get('checklist.item_executor');
    $attempt = $runner->submit($item);
    $this->assertSame(ChecklistAttempt::WAITING, $attempt->status);
    $this->assertSame((int) $this->caller->id(), $attempt->initiator);
    $this->assertSame((int) $this->executorAccount->id(), $attempt->executor);
    $this->assertSame((int) $this->author->id(), $attempt->authorization['authorizer']);
    $this->assertSame($job->id(), $attempt->authorization['job']);
    $this->assertNotEmpty($attempt->authorization['grant']);
    $task->set('assignee', $this->caller)->save();
    $this->now += 10;
    $done = $runner->run($attempt);
    $this->assertSame(ChecklistAttempt::SUCCEEDED, $done->status);
    $this->assertSame([$attempt->executor, $attempt->executor], array_column(Iteration::$calls, 0));
    $this->assertSame($this->caller->id(), $this->container->get('current_user')->id());
    $history = $this->container->get('checklist.item_reader')->readHistory($task, 'checklist', 0, 'work');
    $this->assertSame($attempt->authorization, $history['attempt']['authorization']);
  }

  /**
   * Requires delegation authority even when editing another job property.
   */
  public function testConfigurerWithoutDelegationPermissionCannotChangeWork(): void {
    [$job] = $this->work();
    $job->set('label', 'Changed work');
    $this->expectException(AccessDeniedHttpException::class);
    $job->save();
  }

  /**
   * Does not interpret imported policy as local approval.
   */
  public function testImportCannotSupplyApproval(): void {
    [$job, , $item] = $this->work();
    $job->setSyncing(TRUE);
    $job->save();
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('checklist.item_executor')->submit($item);
  }

  /**
   * Default configuration installs cannot borrow the installing user's consent.
   */
  public function testDefaultConfigurationCannotSupplyApproval(): void {
    [$job, , $item] = $this->work();
    $this->container->get('current_user')->setAccount($this->author);
    // ConfigInstaller uses trustData()->save(), rather than setSyncing(TRUE).
    $job->trustData()->save();
    $this->assertNull($this->container->get('keyvalue')->get('task_job.execution_authorization')->get($job->id()));
    $this->container->get('current_user')->setAccount($this->caller);
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('checklist.item_executor')->submit($item);
  }

  /**
   * Ignores spoofed execution settings on dynamically added items.
   */
  public function testCopiedSettingsDoNotDelegateDynamicItems(): void {
    [, $task] = $this->work();
    $item = ChecklistItem::create([
      'checklist_type' => 'job',
      'name' => 'unconfigured',
      'title' => 'Unconfigured',
      'checklist' => ['entity' => $task, 'checklist_key' => 'checklist'],
      'handler' => [
        'id' => 'iteration_test',
        'configuration' => [
          'context_mapping' => ['value' => 'checklist:entity.title.value'],
          'execution' => ['mode' => 'context', 'authorizer' => 1, 'executor' => 1],
        ],
      ],
    ]);
    $item->save();
    $attempt = $this->container->get('checklist.item_executor')->submit($item);
    $this->assertSame((int) $this->caller->id(), $attempt->executor);
    $this->assertSame('self', $attempt->authorization['source']);
  }

  /**
   * Revokes queued authority when its accounts or definition change.
   *
   * @dataProvider revokedAuthorities
   */
  public function testRevocationBeforeWorker(string $change): void {
    [$job, , $item] = $this->work();
    $runner = $this->container->get('checklist.item_executor');
    $attempt = $runner->submit($item, TRUE);
    if ($change === 'executor') {
      $this->executorAccount->block()->save();
    }
    elseif ($change === 'permission') {
      $role = Role::load('work');
      $role->revokePermission('update any tasks')->save();
    }
    elseif ($change === 'authorizer') {
      $this->author->removeRole('approve')->save();
    }
    else {
      $this->container->get('current_user')->setAccount($this->author);
      $job->set('label', 'Reauthorized work')->save();
      $this->container->get('current_user')->setAccount($this->caller);
    }
    try {
      $runner->run($attempt);
      $this->fail('Revoked authorization must prevent execution.');
    }
    catch (AccessDeniedHttpException) {
      $this->assertSame([], Iteration::$calls);
      $this->assertSame($this->caller->id(), $this->container->get('current_user')->id());
    }
  }

  /**
   * Rejects queued clean-version work after a dirty copy supersedes it.
   */
  public function testDirtyVersionRequiresItsOwnApproval(): void {
    [$job, $task, $item] = $this->work();
    $current = $this->container->get('current_user');
    $current->setAccount($this->author);
    $versions = $this->container->get('task_job.version_resolver');
    $version = $versions->createVersion($job, '6');
    $version->save();
    $task->set('job_version', '6')->save();
    $current->setAccount($this->caller);
    $runner = $this->container->get('checklist.item_executor');
    $attempt = $runner->submit($item, TRUE);
    $this->assertSame('delegated--v6', $attempt->authorization['job']);
    $current->setAccount($this->author);
    $dirty = $versions->createDirtyVersion($version);
    $dirty->save();
    $current->setAccount($this->caller);
    $this->expectException(AccessDeniedHttpException::class);
    $runner->run($attempt);
  }

  /**
   * Rejects results if approval is revoked during a provider call.
   */
  public function testRevocationDuringExecutionPreventsResultApplication(): void {
    [$job, , $item] = $this->work();
    Iteration::$during = function () use ($job): void {
      // Simulate an independent config import while the provider is working.
      $job->setSyncing(TRUE)->save();
    };
    try {
      $this->container->get('checklist.item_executor')->submit($item);
      $this->fail('An unapproved result must not be committed.');
    }
    catch (AccessDeniedHttpException) {
      $stored = $this->container->get('entity_type.manager')->getStorage('checklist_item')->loadUnchanged($item->id());
      $this->assertTrue($stored->isIncomplete());
      $this->assertTrue($stored->get('state')->isEmpty());
      $attempt = $this->container->get('checklist.attempt_journal')->latest($stored);
      $this->assertSame(ChecklistAttempt::FAILED, $attempt->status);
      $this->assertSame($this->caller->id(), $this->container->get('current_user')->id());
    }
  }

  /**
   * Allows an authorized local save to approve an imported policy.
   */
  public function testImportCanBeApprovedLocally(): void {
    [$job, , $item] = $this->work();
    $job->setSyncing(TRUE)->save();
    $this->assertNull($this->container->get('keyvalue')->get('task_job.execution_authorization')->get($job->id()));
    $this->container->get('current_user')->setAccount($this->author);
    $job->setSyncing(FALSE)->save();
    $this->container->get('current_user')->setAccount($this->caller);
    $attempt = $this->container->get('checklist.item_executor')->submit($item, TRUE);
    $this->assertSame((int) $this->author->id(), $attempt->authorization['authorizer']);
  }

  /**
   * Requires initiator access independently of delegated authority.
   */
  public function testInitiatorMustHaveTaskAccess(): void {
    [, , $item] = $this->work();
    $outsider = $this->account('Outsider', []);
    $this->container->get('current_user')->setAccount($outsider);
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('checklist.item_executor')->submit($item);
  }

  /**
   * Invalidates approval when referenced configuration changes.
   */
  public function testReferencedConfigurationChangesRevokeApproval(): void {
    [$job, , $item] = $this->work();
    $this->container->get('current_user')->setAccount($this->author);
    $job->set('dependencies', ['enforced' => ['config' => ['system.site']]])->save();
    $this->container->get('current_user')->setAccount($this->caller);
    $runner = $this->container->get('checklist.item_executor');
    $attempt = $runner->submit($item, TRUE);
    $this->config('system.site')->set('name', 'Changed dependency')->save();
    $this->expectException(AccessDeniedHttpException::class);
    $runner->run($attempt);
  }

  /**
   * Lists the independently revocable sources of authority.
   */
  public static function revokedAuthorities(): array {
    return [['executor'], ['permission'], ['authorizer'], ['definition']];
  }

}
