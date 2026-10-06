<?php

namespace Drupal\Tests\task_job_additions\Kernel;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\task_job\JobConfigurationChecklist;
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
   * Existing receipts receive the default button identity during update.
   */
  public function testReceiptExposureUpdate(): void {
    [, $task] = $this->work();
    $id = $this->container->get('uuid')->generate();
    $manager = $this->container->get('task_job_additions.manager');
    $original = $manager->add($task, 'review', $id);
    $database = $this->container->get('database');
    $database->schema()->dropField('task_job_checklist_addition', 'exposure');
    $this->container->get('module_handler')->loadInclude('task_job_additions', 'install');
    task_job_additions_update_10001();
    task_job_additions_update_10001();
    $this->assertEquals($original, $manager->add($task, 'review', $id));
  }

  /**
   * Two buttons invoke one template with distinct declared input mappings.
   */
  public function testIndependentExposures(): void {
    [$job, $task] = $this->work();
    $templates = $job->get('checklist_templates');
    $templates['review']['context'] = [
      'subject' => ['type' => 'string', 'label' => 'Reference subject', 'required' => TRUE],
    ];
    $templates['review']['items']['record']['handler_configuration']['context_mapping']['value'] = 'template_context:subject';
    $templates['review']['exposures'] = [
      'default' => [
        'enabled' => TRUE,
        'label' => 'Review title',
        'context_mapping' => ['template_context:subject' => 'checklist:entity.title.value'],
      ],
      'description' => [
        'enabled' => TRUE,
        'label' => 'Review description',
        'context_mapping' => ['template_context:subject' => 'checklist:entity.description.value'],
      ],
    ];
    $job->set('checklist_templates', $templates)->save();
    $task->set('description', 'Separate input')->save();
    $collector = $this->container->get('checklist.context_collector');
    $config = $collector->collectConfigContexts(JobConfigurationChecklist::createFromJob($job, NULL, 'review'));
    $this->assertSame('Reference subject', $config['template_context:subject']->getContextDefinition()->getLabel());
    $this->assertArrayNotHasKey('template_context:subject', $collector->collectConfigContexts(JobConfigurationChecklist::createFromJob($job)));
    $manager = $this->container->get('task_job_additions.manager');
    $this->assertSame(['review', 'review:description'], array_keys($manager->discover($task)['templates']));
    $ids = [];
    foreach (['review' => 'Support task', 'review:description' => 'Separate input'] as $choice => $expected) {
      $id = $this->container->get('uuid')->generate();
      $receipt = $manager->add($task, $choice, $id);
      $ids[$choice] = $id;
      $this->assertSame('review', $receipt['template']);
      $checklist = $this->reload($task)->checklist->checklist;
      $item = $checklist->getItem(AdditionDefinitions::prefix($id) . 'record');
      $this->assertTrue($this->container->get('checklist.context_preparer')->prepare($checklist, $item));
      $this->assertSame($expected, $item->getHandler()->getContextValue('value'));
    }
    $templates['review']['exposures']['description']['enabled'] = FALSE;
    $job->set('checklist_templates', $templates)->save();
    $this->assertSame(['review'], array_keys($manager->discover($task)['templates']));
    $checklist = $this->reload($task)->checklist->checklist;
    $item = $checklist->getItem(AdditionDefinitions::prefix($ids['review:description']) . 'record');
    $this->assertTrue($this->container->get('checklist.context_preparer')->prepare($checklist, $item));
    $this->assertSame('Separate input', $item->getHandler()->getContextValue('value'));
    $this->expectException(ConflictHttpException::class);
    $manager->add($task, 'review', $ids['review:description']);
  }

  /**
   * Required template inputs gate even handlers with no own context slots.
   */
  public function testMissingTemplateInput(): void {
    [$job, $task] = $this->work();
    $templates = $job->get('checklist_templates');
    $templates['review']['context'] = ['subject' => ['type' => 'string', 'label' => 'Subject', 'required' => TRUE]];
    $templates['review']['items'] = [
      'confirm' => ['label' => 'Confirm', 'handler' => 'simply_checkable', 'handler_configuration' => []],
    ];
    $job->set('checklist_templates', $templates)->save();
    $id = $this->container->get('uuid')->generate();
    $this->container->get('task_job_additions.manager')->add($task, 'review', $id);
    $checklist = $this->reload($task)->checklist->checklist;
    $this->assertFalse($this->container->get('checklist.context_preparer')->prepare($checklist, $checklist->getItem(AdditionDefinitions::prefix($id) . 'confirm')));
  }

  /**
   * Authored mappings scope inputs without rewriting task or sibling contexts.
   */
  public function testAdditionContextMapping(): void {
    [$job, $task] = $this->work();
    $job->set('context', ['subject' => ['type' => 'string', 'label' => 'Subject', 'required' => FALSE]]);
    $templates = $job->get('checklist_templates');
    $templates['review']['context'] = [
      'subject' => ['type' => 'string', 'label' => 'Subject', 'required' => TRUE],
      'owner' => ['type' => 'entity:user', 'label' => 'Owner', 'required' => FALSE],
    ];
    $templates['review']['addition_context_mapping'] = ['template_context:subject' => 'checklist:entity.title.value'];
    $templates['review']['items']['record']['handler_configuration']['context_mapping']['value'] = 'template_context:subject';
    $templates['inherited'] = $templates['review'];
    unset($templates['inherited']['addition_context_mapping'], $templates['inherited']['context']);
    $templates['inherited']['items']['record']['handler_configuration']['context_mapping']['value'] = 'task_context:subject';
    $templates['review']['items']['decision']['handler_configuration']['options']['yes']['template'] = 'child';
    $templates['child'] = ['label' => 'Nested', 'items' => ['record' => $templates['review']['items']['record']]];
    $job->set('checklist_templates', $templates)->save();
    $task = $this->reload($task);
    $task->get('context')->set('subject', 'Inherited subject');
    $task->save();
    $manager = $this->container->get('task_job_additions.manager');
    $a = $this->container->get('uuid')->generate();
    $b = $this->container->get('uuid')->generate();
    $manager->add($task, 'review', $a);
    $manager->add($task, 'inherited', $b);
    $checklist = $this->reload($task)->checklist->checklist;
    $preparer = $this->container->get('checklist.context_preparer');
    foreach ([$a => 'Support task', $b => 'Inherited subject'] as $id => $expected) {
      $item = $checklist->getItem(AdditionDefinitions::prefix($id) . 'record');
      $this->assertTrue($preparer->prepare($checklist, $item));
      $this->assertSame($expected, $item->getHandler()->getContextValue('value'));
    }
    $this->container->get('checklist.action_operation_dispatcher')->execute($checklist, AdditionDefinitions::prefix($a) . 'decision', 'choose', ['choice' => 'yes']);
    $child = $checklist->getItem(AdditionDefinitions::prefix($a) . 'decision__yes__child__record');
    $this->assertTrue($preparer->prepare($checklist, $child));
    $this->assertSame('Support task', $child->getHandler()->getContextValue('value'));
    $this->assertSame('Inherited subject', $this->reload($task)->get('context')->get('subject')->getValue());
    // Inputs are resolved afresh, rather than snapshotted when added.
    $task->set('title', 'Updated title')->save();
    $checklist = $this->reload($task)->checklist->checklist;
    $item = $checklist->getItem(AdditionDefinitions::prefix($a) . 'record');
    $this->assertTrue($preparer->prepare($checklist, $item));
    $this->assertSame('Updated title', $item->getHandler()->getContextValue('value'));
  }

  /**
   * Missing outcomes block consumers; provider values use normal resolution.
   */
  public function testAdditionOutcomeAndProviderInputs(): void {
    [$job, $task] = $this->work();
    $job->set('context', [
      'subject' => ['type' => 'string', 'label' => 'Subject', 'required' => FALSE],
      'owner' => ['type' => 'entity:user', 'label' => 'Owner', 'required' => FALSE],
    ]);
    $job->set('default_checklist', [
      'source' => [
        'label' => 'Source decision',
        'handler' => 'decision',
        'handler_configuration' => ['options' => ['yes' => ['label' => 'Yes']]],
      ],
    ]);
    $templates = $job->get('checklist_templates');
    $templates['review']['context'] = [
      'subject' => ['type' => 'string', 'label' => 'Subject', 'required' => TRUE],
      'owner' => ['type' => 'entity:user', 'label' => 'Owner', 'required' => FALSE],
    ];
    $templates['review']['addition_context_mapping'] = [
      'template_context:subject' => 'item:source:decision',
      'template_context:owner' => '@user.current_user_context:current_user',
    ];
    $templates['review']['items']['record']['handler_configuration']['context_mapping']['value'] = 'template_context:subject';
    $job->set('checklist_templates', $templates)->save();
    $id = $this->container->get('uuid')->generate();
    $this->container->get('task_job_additions.manager')->add($task, 'review', $id);
    $checklist = $this->reload($task)->checklist->checklist;
    $item = $checklist->getItem(AdditionDefinitions::prefix($id) . 'record');
    $preparer = $this->container->get('checklist.context_preparer');
    $this->assertFalse($preparer->prepare($checklist, $item));
    $this->container->get('checklist.action_operation_dispatcher')->execute($checklist, 'source', 'choose', ['choice' => 'yes']);
    $this->assertTrue($preparer->prepare($checklist, $item));
    $this->assertSame('yes', $item->getHandler()->getContextValue('value'));
    $contexts = $this->container->get('checklist.context_collector')->collectRuntimeContexts($checklist, $item);
    $this->assertSame($this->container->get('current_user')->id(), $contexts['template_context:owner']->getContextValue()->id());
  }

  /**
   * Authored mappings cannot replace the fixed host or undeclared contexts.
   */
  public function testInvalidAdditionMappingDestination(): void {
    [$job, $task] = $this->work();
    $templates = $job->get('checklist_templates');
    $templates['review']['context'] = [
      'subject' => ['type' => 'string', 'label' => 'Subject', 'required' => TRUE],
      'owner' => ['type' => 'entity:user', 'label' => 'Owner', 'required' => FALSE],
    ];
    $templates['review']['addition_context_mapping'] = [
      'checklist:entity' => '@user.current_user_context:current_user',
    ];
    $job->set('checklist_templates', $templates)->save();
    try {
      $this->container->get('task_job_additions.manager')->add($task, 'review', $this->container->get('uuid')->generate());
      $this->fail('Host context replacement must be rejected.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('declared template inputs', $exception->getMessage());
      $this->assertSame([], $this->container->get('task_job_additions.storage')->forTask($task->uuid()));
    }
  }

  /**
   * Row refreshes add and remove choices as current task conditions change.
   */
  public function testWorkspaceRefresh(): void {
    [$job, $task] = $this->work();
    $templates = $job->get('checklist_templates');
    $templates['review']['addition_condition'] = [
      'id' => 'condition_string',
      'condition_string' => 'checklist.title.value == "Support task"',
    ];
    $job->set('checklist_templates', $templates)->save();
    $hashes = [];
    foreach (['Support task', 'Support task', 'Other task'] as $title) {
      $task->set('title', $title)->save();
      $checklist = $this->container->get('checklist.resolver')->resolve($this->reload($task), 'checklist');
      $response = new AjaxResponse();
      $this->container->get('checklist.row_updater')->refresh($response, $checklist);
      $commands = array_values(array_filter($response->getCommands(), static fn(array $command): bool => $command['command'] === 'taskJobAdditionChoices'));
      $this->assertCount(1, $commands);
      $this->assertSame('#task-job-additions-' . $task->uuid(), $commands[0]['selector']);
      $this->assertSame($title === 'Support task', str_contains($commands[0]['data'], 'Review evidence'));
      $hashes[] = $commands[0]['choicesHash'];
    }
    $this->assertSame($hashes[0], $hashes[1]);
    $this->assertNotSame($hashes[1], $hashes[2]);
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
    $job->set('context', ['subject' => ['type' => 'string', 'label' => 'Subject', 'required' => FALSE]])->save();
    $versions = $this->container->get('task_job.version_resolver');
    $version = $versions->createVersion($job, '6');
    $templates = $version->get('checklist_templates');
    $templates['review']['context'] = [
      'subject' => ['type' => 'string', 'label' => 'Subject', 'required' => TRUE],
      'owner' => ['type' => 'entity:user', 'label' => 'Owner', 'required' => FALSE],
    ];
    $templates['review']['addition_context_mapping'] = ['template_context:subject' => 'checklist:entity.title.value'];
    $version->set('checklist_templates', $templates)->save();
    $task->set('description', 'Dirty mapped input');
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
    $templates = $latest->get('checklist_templates');
    $templates['review']['context'] = [
      'subject' => ['type' => 'string', 'label' => 'Subject', 'required' => TRUE],
      'owner' => ['type' => 'entity:user', 'label' => 'Owner', 'required' => FALSE],
    ];
    $templates['review']['addition_context_mapping'] = [
      'template_context:subject' => 'checklist:entity.description.value',
    ];
    $latest->set('checklist_templates', $templates)->save();
    $pinned = $this->reload($task)->checklist->checklist;
    $contexts = $this->container->get('checklist.context_collector')->collectRuntimeContexts($pinned, $pinned->getItem($prefix . 'record'));
    $this->assertSame('Support task', $contexts['template_context:subject']->getContextValue());
    $this->assertSame('Record outcome', $this->reload($task)->checklist->checklist->getItem($prefix . 'record')->get('title')->value);
    $dirty = $versions->createDirtyVersion($version);
    $items['record']['label'] = 'Corrected outcome';
    $items['decision']['label'] = 'Changed decision';
    $dirty->setChecklistItems($items, 'review');
    $templates = $dirty->get('checklist_templates');
    $templates['review']['addition_condition'] = ['id' => 'condition_constant:false'];
    $templates['review']['context'] = [
      'subject' => ['type' => 'string', 'label' => 'Subject', 'required' => TRUE],
      'owner' => ['type' => 'entity:user', 'label' => 'Owner', 'required' => FALSE],
    ];
    $templates['review']['addition_context_mapping'] = [
      'template_context:subject' => 'checklist:entity.description.value',
    ];
    $dirty->set('checklist_templates', $templates);
    $dirty->save();
    $this->assertSame([], $manager->discover($task)['templates']);
    $checklist = $this->reload($task)->checklist->checklist;
    $this->assertSame($dirty->id(), $checklist->getType()->getJob()->id());
    $contexts = $this->container->get('checklist.context_collector')->collectRuntimeContexts($checklist, $checklist->getItem($prefix . 'record'));
    $this->assertSame('Dirty mapped input', $contexts['template_context:subject']->getContextValue());
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
    $job->set('context', ['worker' => ['type' => 'entity:user', 'label' => 'Worker']]);
    $templates['automatic'] = [
      'label' => 'Automatic work',
      'context' => ['worker' => ['type' => 'entity:user', 'label' => 'Worker', 'required' => TRUE]],
      'addition_context_mapping' => ['template_context:worker' => 'checklist:entity.assignee.entity'],
      'allow_addition' => TRUE,
      'items' => [
        'work' => [
          'label' => 'Record task',
          'handler' => 'iteration_test',
          'handler_configuration' => ['context_mapping' => ['value' => 'checklist:entity.title.value']],
          'execution' => ['mode' => 'context', 'context_mapping' => ['executor' => 'template_context:worker']],
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

  /**
   * Discovery and mutation recheck current data without changing existing work.
   */
  public function testAvailabilityAndStaleRequest(): void {
    [$job, $task] = $this->work();
    $templates = $job->get('checklist_templates');
    $templates['review']['addition_condition'] = [
      'id' => 'condition_string',
      'condition_string' => 'checklist.title.value == "Support task"',
    ];
    $job->set('checklist_templates', $templates)->save();
    $manager = $this->container->get('task_job_additions.manager');
    $this->assertArrayHasKey('review', $manager->discover($task)['templates']);
    $id = $this->container->get('uuid')->generate();
    $manager->add($task, 'review', $id);
    $changed = $this->reload($task);
    $changed->set('title', 'No more reviews')->save();
    // The caller still holds the task from before the title changed.
    $this->assertSame([], $manager->discover($task)['templates']);
    $this->assertSame($id, $manager->add($task, 'review', $id)['id']);
    $existing = $this->reload($task)->checklist->checklist;
    $this->assertTrue($existing->isItemActive($existing->getItem(AdditionDefinitions::prefix($id) . 'decision')));
    try {
      $manager->add($task, 'review', $this->container->get('uuid')->generate());
      $this->fail('A stale discovery response cannot authorize new work.');
    }
    catch (AccessDeniedHttpException) {
      $this->assertCount(1, $manager->discover($task)['additions']);
    }
    $changed->set('title', 'Support task')->save();
    $this->assertArrayHasKey('review', $manager->discover($task)['templates']);
  }

  /**
   * Conditions consume persisted outcomes, groups, and global user contexts.
   */
  public function testOutcomeAndProviderConditions(): void {
    [$job, $task] = $this->work();
    $job->set('default_checklist', [
      'gate' => [
        'label' => 'Review needed?',
        'handler' => 'decision',
        'handler_configuration' => ['options' => ['yes' => ['label' => 'Yes']]],
      ],
    ]);
    $templates = $job->get('checklist_templates');
    $templates['review']['addition_condition'] = [
      'id' => 'condition_and',
      'conditions' => [
        ['id' => 'condition_string', 'condition_string' => 'items.gate.outcomes.decision == "yes"'],
        [
          'id' => 'user_role',
          'roles' => ['staff'],
          'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        ],
      ],
    ];
    $job->set('checklist_templates', $templates)->save();
    $manager = $this->container->get('task_job_additions.manager');
    $this->assertSame([], $manager->discover($task)['templates']);
    $checklist = $this->reload($task)->checklist->checklist;
    $this->container->get('checklist.action_operation_dispatcher')->execute($checklist, 'gate', 'choose', ['choice' => 'yes']);
    $this->assertArrayHasKey('review', $manager->discover($task)['templates']);
    $manager->add($task, 'review', $this->container->get('uuid')->generate());
    $this->assertCount(1, $manager->discover($task)['additions']);
    $job->calculateDependencies();
    $this->assertContains('typed_data_plus', $job->getDependencies()['module']);
    $this->assertContains('user', $job->getDependencies()['module']);
  }

  /**
   * Missing required values cannot become permission through negation.
   */
  public function testMissingRequiredAvailabilityContext(): void {
    [$job, $task] = $this->work();
    $job->set('default_checklist', [
      'source' => [
        'label' => 'Source',
        'handler' => 'context_producer',
        'handler_configuration' => [],
      ],
    ]);
    $templates = $job->get('checklist_templates');
    $templates['review']['addition_condition'] = [
      'id' => 'user_role',
      'roles' => ['staff'],
      'negate' => TRUE,
      'context_mapping' => ['user' => 'item:source:user'],
    ];
    $job->set('checklist_templates', $templates)->save();
    $manager = $this->container->get('task_job_additions.manager');
    $this->assertSame([], $manager->discover($task)['templates']);
    $this->expectException(AccessDeniedHttpException::class);
    $manager->add($task, 'review', $this->container->get('uuid')->generate());
  }

}
