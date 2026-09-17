<?php

namespace Drupal\Tests\task\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\note\Entity\Note;
use Drupal\service\Entity\Service;
use Drupal\service\Entity\ServiceType;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\user\Entity\User;

/**
 * Tests the independently installed job, checklist, service and note modules.
 *
 * @group task
 */
class TaskIntegrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity',
    'task', 'task_context', 'task_checklist', 'task_job', 'checklist',
    'plugin_reference', 'typed_data', 'typed_data_reference',
    'typed_data_context_assignment', 'entity_template', 'typed_data_plus', 'entity_template_ui',
    'inline_entity_form', 'views', 'service', 'note',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('task_job', ['task_job_trigger_index']);
    foreach (['user', 'note', 'service', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user']);
  }

  /**
   * Jobs create checklists; services assign work; notes follow the task root.
   */
  public function testServiceJobAndNotes(): void {
    $manager = User::create(['name' => 'manager', 'status' => 1]);
    $manager->save();
    ServiceType::create(['id' => 'recruitment', 'label' => 'Recruitment'])->save();
    $service = Service::create(['type' => 'recruitment', 'label' => 'Recruitment support', 'manager' => $manager]);
    $service->save();
    $job = Job::create([
      'id' => 'review',
      'label' => 'Review',
      'default_checklist' => [
        'review' => ['label' => 'Review the request', 'handler' => 'simply_checkable', 'handler_configuration' => []],
      ],
    ]);
    $job->save();
    $this->assertSame('service_manager', $job->get('assignment'));
    $this->assertTrue($this->container->has('service.task_assignee_subscriber'));
    $this->assertEquals($manager->id(), $service->manager->target_id);
    $task = Task::create(['title' => 'Review request', 'job' => $job, 'service' => $service]);
    $task->save();
    $this->assertEquals($service->id(), $task->service->target_id);
    $this->assertEquals($manager->id(), $task->assignee->target_id);
    $this->assertSame('active', $task->status->value);
    $this->assertNotNull($task->checklist->checklist);
    $this->assertCount(1, $task->checklist->checklist->getItems());
    $note = Note::create(['subject' => 'Progress', 'task' => $task]);
    $note->save();
    $this->assertEquals($task->id(), $note->root_task->target_id);
    $this->assertEquals($service->id(), $note->service->target_id);
    $checklist = $task->checklist->checklist;
    $this->assertFalse($checklist->isCompletable());
    $checklist->getItem('review')->setComplete()->save();
    $checklist->complete();
    $this->assertSame('resolved', $task->status->value);
  }

  /**
   * Job assignment rules never replace a supplied assignee.
   */
  public function testAssignmentRules(): void {
    $creator = User::create(['name' => 'creator', 'status' => 1]);
    $creator->save();
    $explicit = User::create(['name' => 'explicit', 'status' => 1]);
    $explicit->save();
    $job = Job::create(['id' => 'assign', 'label' => 'Assign', 'assignment' => 'creator']);
    $job->save();
    $task = Task::create(['title' => 'Automatic', 'job' => $job, 'creator' => $creator]);
    $task->save();
    $this->assertEquals($creator->id(), $task->assignee->target_id);
    $task = Task::create(['title' => 'Explicit', 'job' => $job, 'creator' => $creator, 'assignee' => $explicit]);
    $task->save();
    $this->assertEquals($explicit->id(), $task->assignee->target_id);
    $job->set('assignment', 'unassigned')->save();
    $task = Task::create(['title' => 'Unassigned', 'job' => $job, 'creator' => $creator]);
    $task->save();
    $this->assertTrue($task->assignee->isEmpty());
  }

}
