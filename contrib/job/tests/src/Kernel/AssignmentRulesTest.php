<?php

namespace Drupal\Tests\task_job\Kernel;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\task_job\JobConfigurationChecklist;
use Drupal\user\Entity\User;

/**
 * Tests ordered assignment during real task saves and version resolution.
 *
 * @group task_job
 */
class AssignmentRulesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime',
    'entity', 'task', 'task_context', 'task_checklist', 'checklist',
    'task_job', 'entity_template', 'typed_data', 'typed_data_plus', 'views',
    'plugin_reference', 'typed_data_reference', 'typed_data_context_assignment',
    'inline_entity_form',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('task_checklist', ['task_checklist_request']);
    $this->installSchema('task_job', ['task_job_trigger_index']);
    foreach (['user', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user']);
  }

  /**
   * Rules respect order, fallback, blocked accounts and explicit owners.
   */
  public function testOrderedAssignments(): void {
    $creator = User::create(['name' => 'Creator', 'status' => 1]);
    $creator->save();
    $reviewer = User::create(['name' => 'Reviewer', 'status' => 1]);
    $reviewer->save();
    $job = Job::create(['id' => 'review', 'label' => 'Review']);
    $job->addContextDefinition('reviewer', ContextDefinition::create('entity:user')->setLabel('Reviewer')->setRequired(FALSE));
    $special = [
      'label' => 'Urgent reviewer',
      'condition' => [
        'id' => 'condition_string',
        'condition_string' => 'task.title.value == "Urgent"',
        // Imported stale mappings must not replace the task being assigned.
        'context_mapping' => ['task' => 'other_task'],
      ],
      'context_mapping' => ['assignee' => 'task_context:reviewer'],
    ];
    $fallback = ['label' => 'Creator rule', 'context_mapping' => ['assignee' => 'task.creator.entity']];
    $job->set('assignment_rules', ['urgent' => $special, 'creator' => $fallback])->save();
    $task = Task::create(['title' => 'Urgent', 'job' => $job, 'creator' => $creator]);
    $task->get('context')->set('reviewer', $reviewer);
    $task->save();
    $this->assertEquals($reviewer->id(), $task->assignee->target_id);
    $task->set('title', 'Ordinary')->save();
    $this->assertEquals($reviewer->id(), $task->assignee->target_id, 'Later saves preserve an assigned user.');
    $task = Task::create(['title' => 'Ordinary', 'job' => $job, 'creator' => $creator]);
    $task->save();
    $this->assertEquals($creator->id(), $task->assignee->target_id);
    $job->set('assignment_rules', ['creator' => $fallback, 'urgent' => $special])->save();
    $task = Task::create(['title' => 'Urgent', 'job' => $job, 'creator' => $creator]);
    $task->get('context')->set('reviewer', $reviewer);
    $task->save();
    $this->assertEquals($creator->id(), $task->assignee->target_id, 'Changing order changes the winner.');
    $job->set('assignment_rules', ['urgent' => $special])->save();
    $task = Task::create(['title' => 'Ordinary', 'job' => $job, 'creator' => $creator]);
    $task->save();
    $this->assertTrue($task->assignee->isEmpty(), 'No match leaves the task unassigned.');
    $task = Task::create(['title' => 'Urgent', 'job' => $job, 'creator' => $creator]);
    $task->save();
    $this->assertTrue($task->assignee->isEmpty(), 'Matched rule with missing account does not fall through.');
    $reviewer->block()->save();
    $task = Task::create(['title' => 'Urgent', 'job' => $job, 'creator' => $creator]);
    $task->get('context')->set('reviewer', $reviewer);
    $task->save();
    $this->assertTrue($task->assignee->isEmpty(), 'Blocked accounts cannot receive work.');
    $task = Task::create(['title' => 'Urgent', 'job' => $job, 'creator' => $creator, 'assignee' => $creator]);
    $task->save();
    $this->assertEquals($creator->id(), $task->assignee->target_id, 'Explicit assignment wins.');
    $this->assertContains('typed_data_plus', $job->getDependencies()['module']);
  }

  /**
   * A task's pinned version and saved dirty overlay supply assignment rules.
   */
  public function testVersionedAssignment(): void {
    $creator = User::create(['name' => 'Creator', 'status' => 1]);
    $creator->save();
    $job = Job::create(['id' => 'versions', 'label' => 'Versions']);
    $job->save();
    $draft = clone $job;
    $draft->addContextDefinition('draft_owner', ContextDefinition::create('entity:user')->setLabel('Draft owner'));
    $checklist = JobConfigurationChecklist::createFromJob($draft);
    $contexts = $this->container->get('checklist.context_collector')->collectConfigContexts($checklist);
    $this->assertArrayHasKey('task_context:draft_owner', $contexts, 'Unsaved context definitions remain available to authoring.');
    $resolver = $this->container->get('task_job.version_resolver');
    $six = $resolver->createVersion($job, '6');
    $six->addContextDefinition('version_owner', ContextDefinition::create('entity:user')->setLabel('Version owner'));
    $six->set('assignment_rules', [
      'creator' => [
        'label' => 'Creator',
        'context_mapping' => ['assignee' => 'task_context:version_owner'],
      ],
    ])->save();
    $seven = $resolver->createVersion($job, '7');
    $seven->save();
    $task = Task::create(['title' => 'Pinned', 'job' => $job, 'job_version' => '6', 'creator' => $creator]);
    $task->get('context')->set('version_owner', $creator);
    $task->save();
    $this->assertEquals($creator->id(), $task->assignee->target_id);
    $dirty = $resolver->createDirtyVersion($six);
    $dirty->set('assignment_rules', [])->save();
    $task = Task::create(['title' => 'Dirty', 'job' => $job, 'job_version' => '6', 'creator' => $creator]);
    $task->save();
    $this->assertTrue($task->assignee->isEmpty());
  }

  /**
   * Core conditions and global providers use the same context handler.
   */
  public function testConditionsAndGlobalContexts(): void {
    $account = User::create(['name' => 'Operator', 'status' => 1]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
    $job = Job::create(['id' => 'providers', 'label' => 'Providers']);
    $job->addContextDefinition('reviewer', ContextDefinition::create('entity:user')->setLabel('Reviewer')->setRequired(FALSE));
    $job->set('assignment_rules', [
      'reviewer' => [
        'label' => 'Authenticated reviewer',
        'condition' => [
          'id' => 'user_role',
          'roles' => ['authenticated' => 'authenticated'],
          'context_mapping' => ['user' => 'task_context:reviewer'],
        ],
        'context_mapping' => ['assignee' => 'task_context:reviewer'],
      ],
      'operator' => [
        'label' => 'Operator',
        'condition' => [
          'id' => 'condition_and',
          'conditions' => [
            ['id' => 'condition_constant:true'],
            ['id' => 'condition_string', 'condition_string' => 'reviewer empty'],
          ],
        ],
        'context_mapping' => ['assignee' => '@user.current_user_context:current_user'],
      ],
    ])->save();
    $task = Task::create(['title' => 'Missing reviewer', 'job' => $job]);
    $task->save();
    $this->assertEquals($account->id(), $task->assignee->target_id);
    $reviewer = User::create(['name' => 'Reviewer', 'status' => 1]);
    $reviewer->save();
    $task = Task::create(['title' => 'Supplied reviewer', 'job' => $job]);
    $task->get('context')->set('reviewer', $reviewer);
    $task->save();
    $this->assertEquals($reviewer->id(), $task->assignee->target_id);
    $this->expectException(PluginNotFoundException::class);
    $this->container->get('task_job.assignment_rules')->matches(['condition' => ['id' => 'missing_condition']], []);
  }

  /**
   * Legacy defaults become final rules without replacing existing rule keys.
   */
  public function testLegacyAssignmentUpgrade(): void {
    $storage = $this->container->get('config.storage');
    $existing = [
      'label' => 'Existing',
      'condition' => ['id' => 'condition_constant:false'],
      'context_mapping' => ['assignee' => 'task.creator.entity'],
    ];
    foreach (['legacy' => 'creator', 'legacy--v6' => 'service_manager', 'legacy--v6-dirty' => 'unassigned'] as $id => $policy) {
      $storage->write('task_job.task_job.' . $id, [
        'id' => $id,
        'assignment' => $policy,
        'assignment_rules' => ['default_assignment' => $existing],
      ]);
    }
    $this->container->get('module_handler')->loadInclude('task_job', 'install');
    task_job_update_8006();
    foreach ([
      'legacy' => 'task.creator.entity',
      'legacy--v6' => 'task.service.entity.manager.entity',
      'legacy--v6-dirty' => NULL,
    ] as $id => $selector) {
      $data = $storage->read('task_job.task_job.' . $id);
      $this->assertArrayNotHasKey('assignment', $data);
      $this->assertSame($existing, $data['assignment_rules']['default_assignment']);
      $this->assertCount($selector ? 2 : 1, $data['assignment_rules']);
      if ($selector) {
        $rule = $data['assignment_rules']['default_assignment_'];
        $this->assertSame($selector, $rule['context_mapping']['assignee']);
        $this->assertArrayNotHasKey('condition', $rule);
      }
    }
    task_job_update_8006();
    $this->assertCount(2, $storage->read('task_job.task_job.legacy')['assignment_rules']);
  }

}
