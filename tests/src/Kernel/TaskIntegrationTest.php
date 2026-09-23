<?php

namespace Drupal\Tests\task\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
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
    'inline_entity_form', 'views', 'service', 'note', 'task_readiness_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('service', ['service_hierarchy_lock']);
    $this->container->get('database')->insert('service_hierarchy_lock')->fields(['id' => 1])->execute();
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
    $service = Service::create([
      'type' => 'recruitment',
      'label' => 'Recruitment support',
      'status' => 'active',
      'manager' => $manager,
    ]);
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
   * A shared task checklist follows the default host revision and fresh inputs.
   */
  public function testSharedChecklistExecutionBinding(): void {
    $account = User::create(['name' => 'administrator', 'status' => 1]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
    $job = Job::create([
      'id' => 'shared',
      'label' => 'Shared checklist',
      'default_checklist' => [
        'review' => ['label' => 'Review', 'handler' => 'simply_checkable', 'handler_configuration' => []],
      ],
    ]);
    $job->save();
    $task = Task::create(['title' => 'Original', 'job' => $job]);
    $task->save();
    $this->assertTrue($task->getEntityType()->isRevisionable());
    $this->assertFalse($task->getFieldDefinition('checklist')->getFieldStorageDefinition()->isRevisionable());
    $item = $task->checklist->checklist->getItem('review');
    $item->save();
    $preparer = $this->container->get('checklist.item_execution_preparer');
    [, $before] = $preparer->prepare($item->uuid(), FALSE);
    $task->setNewRevision(TRUE);
    $task->set('title', 'Updated')->save();
    [$checklist, $current] = $preparer->load($item->uuid(), FALSE);
    $this->assertSame($item->uuid(), $current->uuid());
    $this->assertEquals($task->getRevisionId(), $checklist->getEntity()->getRevisionId());
    $this->assertSame('Updated', $checklist->getEntity()->label());
    [, $after] = $preparer->prepare($item->uuid(), FALSE);
    $this->assertNotSame($before, $after);

    // Configurable fields are revisionable and cannot use this shared binding.
    FieldStorageConfig::create([
      'field_name' => 'revision_work',
      'entity_type' => 'task',
      'type' => 'checklist',
    ])->save();
    FieldConfig::create([
      'field_name' => 'revision_work',
      'entity_type' => 'task',
      'bundle' => 'task',
      'translatable' => FALSE,
    ])->save();
    $other = Task::create([
      'title' => 'Revision-specific work',
      'revision_work' => ['id' => 'job', 'configuration' => ['job' => $job->id()]],
    ]);
    $other->save();
    $revision_item = $other->revision_work->checklist->getItem('review');
    $revision_item->save();
    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage('Revisioned, multivalue and translated checklist bindings need a workspace adapter.');
    $preparer->load($revision_item->uuid(), FALSE);
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

  /**
   * Services cannot be deleted until current task references are removed.
   */
  public function testServiceDeletionWithTask(): void {
    ServiceType::create(['id' => 'work', 'label' => 'Work'])->save();
    $service = Service::create(['type' => 'work', 'label' => 'Parent']);
    $service->save();
    $task = Task::create(['title' => 'Dependent work', 'service' => $service]);
    $task->save();
    try {
      $service->delete();
      $this->fail('A task reference must prevent deletion.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('dependent', $exception->getMessage());
    }
    $task->set('service', NULL)->save();
    $service->delete();
    $this->assertNull(Service::load($service->id()));
    $this->assertNotNull(Task::load($task->id()));
  }

  /**
   * Task writes cannot attach to a deleted service through a cached reference.
   */
  public function testTaskWithDeletedService(): void {
    ServiceType::create(['id' => 'work', 'label' => 'Work'])->save();
    $service = Service::create(['type' => 'work', 'label' => 'Parent']);
    $service->save();
    $task = Task::create(['title' => 'Work', 'service' => $service]);
    $service->delete();
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('missing');
    $task->save();
  }

  /**
   * Changing only a task's reference invalidates its cached hierarchy result.
   */
  public function testTaskServiceCacheMetadata(): void {
    ServiceType::create(['id' => 'work', 'label' => 'Work'])->save();
    $first = Service::create(['type' => 'work', 'label' => 'First']);
    $first->save();
    $second = Service::create(['type' => 'work', 'label' => 'Second']);
    $second->save();
    $task = Task::create(['title' => 'Work', 'service' => $first]);
    $task->save();
    $property = $task->get('service')->first()->get('root');
    $metadata = CacheableMetadata::createFromObject($property);
    $this->assertContains('task:' . $task->id(), $metadata->getCacheTags());
    $cache = $this->container->get('cache.render');
    $cache->set('task_root', $first->id(), Cache::PERMANENT, $metadata->getCacheTags());
    $this->assertNotFalse($cache->get('task_root'));
    $task->set('service', $second)->save();
    $this->assertFalse($cache->get('task_root'));
    $this->assertSame($second->id(), $task->get('service')->first()->get('root')->getTargetIdentifier());
  }

  /**
   * Fetcher metadata invalidates output when an intermediate service moves.
   */
  public function testFetchedHierarchyCacheInvalidation(): void {
    ServiceType::create(['id' => 'work', 'label' => 'Work'])->save();
    $first = Service::create(['type' => 'work', 'label' => 'First root']);
    $first->save();
    $second = Service::create(['type' => 'work', 'label' => 'Second root']);
    $second->save();
    $parent = Service::create(['type' => 'work', 'label' => 'Parent', 'service' => $first]);
    $parent->save();
    $task = Task::create(['title' => 'Work', 'service' => $parent]);
    $task->save();
    $fetcher = $this->container->get('typed_data_plus.data_fetcher');
    $metadata = new BubbleableMetadata();
    $value = $fetcher->fetchFilteredData($task->getTypedData(), 'service.root.label.value', $metadata);
    $this->assertSame('First root', $value->getValue());
    $this->assertContains('service_list', $metadata->getCacheTags());
    $this->assertContains('task:' . $task->id(), $metadata->getCacheTags());
    $cache = $this->container->get('cache.render');
    $cache->set('fetched_root', $value->getValue(), Cache::PERMANENT, $metadata->getCacheTags());
    $this->assertNotFalse($cache->get('fetched_root'));

    // Neither the task nor the old root changes: only the hidden ancestor link.
    $parent->set('service', $second)->save();
    $this->assertFalse($cache->get('fetched_root'));
    $value = $fetcher->fetchFilteredData($task->getTypedData(), 'service.root.label.value');
    $this->assertSame('Second root', $value->getValue());

    $metadata = new BubbleableMetadata();
    $all = $fetcher->fetchFilteredData($task->getTypedData(), 'service.all', $metadata);
    $this->assertCount(2, $all->getValue());
    $this->assertContains('service_list', $metadata->getCacheTags());
  }

  /**
   * Processing respects dependencies, service changes and a postponed start.
   */
  public function testChecklistReadinessGates(): void {
    ServiceType::create(['id' => 'work', 'label' => 'Work'])->save();
    $service = Service::create(['type' => 'work']);
    $service->save();
    $job = Job::create([
      'id' => 'gated',
      'label' => 'Gated work',
      'default_checklist' => [
        'review' => ['label' => 'Review', 'handler' => 'simply_checkable', 'handler_configuration' => []],
      ],
    ]);
    $job->save();
    $dependency = Task::create(['title' => 'Prerequisite']);
    $dependency->save();
    $task = Task::create(['title' => 'Work', 'job' => $job, 'service' => $service, 'dependencies' => [$dependency]]);
    $task->save();
    $task->checklist->checklist->getItem('review')->setComplete()->save();
    $processor = $this->container->get('task_checklist.task_processor');
    $processor->processTask($task);
    $this->assertSame('pending', Task::load($task->id())->status->value);
    $service->set('status', 'active')->save();
    $task->save();
    $processor->processTask($task);
    $this->assertSame('waiting', Task::load($task->id())->status->value);

    // Keep the service draft while the prerequisite resolves.
    $service->set('status', 'draft')->save();
    $dependency->resolve()->save();
    $processor->processTask($task);
    $this->assertSame('pending', Task::load($task->id())->status->value);
    $service->set('status', 'complete')->save();
    $processor->processTask($task);
    $this->assertSame('pending', Task::load($task->id())->status->value);
    $service->set('status', 'active')->save();
    $held = Task::load($task->id());
    $held->set('start', '2099-01-01T00:00:00')->save();
    $processor->processTask($task);
    $this->assertSame('pending', Task::load($task->id())->status->value);
    $held->set('start', '2000-01-01T00:00:00')->save();
    $this->assertSame('resolved', Task::load($task->id())->status->value);
  }

  /**
   * Processing applies consumer invalidation once and preserves final outcomes.
   */
  public function testProcessorInvalidation(): void {
    $task = Task::create(['title' => 'No longer needed']);
    $task->save();
    $this->container->get('state')->set('task_readiness_test.reasons', [
      ['state' => 'invalid', 'code' => 'example_cancelled_work'],
    ]);
    $processor = $this->container->get('task_checklist.task_processor');
    $processor->processTask($task);
    $saved = Task::load($task->id());
    $this->assertSame('resolved', $saved->status->value);
    $this->assertSame('invalid', $saved->resolution->value);
    $resolved = $saved->resolved->value;
    $processor->processTask($task);
    $this->assertSame($resolved, Task::load($task->id())->resolved->value);
  }

}
