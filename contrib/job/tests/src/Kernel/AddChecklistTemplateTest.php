<?php

namespace Drupal\Tests\task_job\Kernel;

use Drupal\checklist\Attempt\ChecklistAttempt;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\task_job\JobChecklistExpansion;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests audited, scoped template activation without addition-button exposure.
 *
 * @group task_job
 */
class AddChecklistTemplateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime',
    'entity', 'task', 'task_context', 'task_checklist', 'checklist',
    'task_job', 'entity_template', 'typed_data', 'typed_data_plus', 'views',
    'plugin_reference', 'typed_data_reference', 'typed_data_context_assignment',
    'inline_entity_form', 'checklist_context_test',
  ];

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
    $user = User::create(['name' => 'Administrator', 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Creates an invocation with a required, explicitly mapped template input.
   */
  protected function invocation(string $source = 'checklist:entity.title.value', string $template = 'review'): array {
    return [
      'label' => 'Add review work',
      'handler' => 'add_checklist_template',
      'handler_configuration' => [
        'template' => $template,
        'context_mapping' => ['template_context:subject' => $source],
      ],
    ];
  }

  /**
   * Creates reusable work that consumes its invocation's input.
   */
  protected function job(): Job {
    $job = Job::create([
      'id' => 'support',
      'label' => 'Support',
      'default_checklist' => ['expand' => $this->invocation()],
      'checklist_templates' => [
        'review' => [
          'label' => 'Review',
          'context' => ['subject' => ['type' => 'string', 'label' => 'Subject', 'required' => TRUE]],
          'items' => [
            'consume' => [
              'label' => 'Review subject',
              'handler' => 'context_consumer',
              'handler_configuration' => ['context_mapping' => ['value' => 'template_context:subject']],
            ],
          ],
        ],
      ],
    ]);
    $job->save();
    return $job;
  }

  /**
   * Saves item identities as task processing does, without executing work.
   */
  protected function task(Job $job): Task {
    $task = Task::create(['title' => 'Title input', 'description' => 'Description input', 'job' => $job]);
    $task->save();
    foreach ($task->checklist->checklist->getOrderedItems() as $item) {
      $item->save();
    }
    return $task;
  }

  /**
   * Stable instances remain adjacent, isolated, audited and replay safe.
   */
  public function testScopedProcessingAndReplay(): void {
    $job = $this->job();
    $job->setChecklistItems([
      'expand' => $this->invocation(),
      'second' => $this->invocation('checklist:entity.description.value'),
    ]);
    $job->save();
    $task = $this->task($job);
    $checklist = $task->checklist->checklist;
    $this->assertSame(['expand', 'second'], array_keys($checklist->getOrderedItems()));
    $this->assertTrue($this->container->get('checklist.processor')->process($checklist));
    $child = $checklist->getItem('expand__template__review__consume');
    $this->assertTrue($checklist->isItemActive($child));
    $this->assertSame([['Title input', NULL], ['Description input', NULL]], $this->container->get('state')->get('checklist_context_test.runs'));
    $parent = $checklist->getItem('expand');
    $this->assertSame('review', $parent->get('outcomes')->get('template')->getValue());
    $journal = $this->container->get('checklist.attempt_journal');
    $attempt = $journal->latest($parent);
    $this->assertSame(ChecklistAttempt::SUCCEEDED, $attempt->status);
    $this->assertNotEmpty($journal->history($attempt->id));
    $uuid = $child->uuid();
    $this->assertSame($attempt->id, $this->container->get('checklist.item_executor')->submit($parent)->id);
    $fresh = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id())->checklist->checklist;
    $this->assertCount(4, $fresh->getItems());
    $this->assertSame($uuid, $fresh->getItem($child->getName())->uuid());
  }

  /**
   * Normal task processing persists and completes the expansion in one pass.
   */
  public function testTaskProcessing(): void {
    $task = Task::create([
      'title' => 'Scheduled review',
      'job' => $this->job(),
      'start' => '2000-01-01T00:00:00',
    ]);
    $task->save();
    $this->container->get('task_checklist.task_processor')->processTask($task);
    $fresh = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id());
    $this->assertSame(Task::STATUS_RESOLVED, $fresh->get('status')->value);
    $this->assertTrue($fresh->checklist->checklist->getItem('expand__template__review__consume')->isComplete());
  }

  /**
   * Named versions isolate new releases while dirty fixes update existing work.
   */
  public function testVersionedDefinitions(): void {
    $job = $this->job();
    $versions = $this->container->get('task_job.version_resolver');
    $six = $versions->createVersion($job, '6');
    $six->save();
    $task = Task::create(['title' => 'Title input', 'job' => $job, 'job_version' => '6']);
    $task->save();
    foreach ($task->checklist->checklist->getItems() as $item) {
      $item->save();
    }
    $checklist = $task->checklist->checklist;
    $this->container->get('checklist.item_executor')->submit($checklist->getItem('expand'));
    $checklist = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id())->checklist->checklist;
    $child_uuid = $checklist->getItem('expand__template__review__consume')->uuid();
    $seven = $versions->createVersion($job, '7');
    $templates = $seven->get('checklist_templates');
    $templates['review']['items']['consume']['label'] = 'Version seven';
    $seven->set('checklist_templates', $templates)->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('task');
    $this->assertSame('Review subject', $storage->loadUnchanged($task->id())->checklist->checklist->getItem('expand__template__review__consume')->get('title')->value);
    $dirty = $versions->createDirtyVersion($six);
    $templates = $dirty->get('checklist_templates');
    $templates['review']['items']['consume']['label'] = 'Corrected review';
    $dirty->set('checklist_templates', $templates)->save();
    $fresh = $storage->loadUnchanged($task->id())->checklist->checklist;
    $child = $fresh->getItem('expand__template__review__consume');
    $this->assertSame('Corrected review', $child->get('title')->value);
    $this->assertSame($child_uuid, $child->uuid());
    $this->assertTrue($fresh->isItemActive($child));
  }

  /**
   * Collection children follow named versions and dirty fixes without rekeying.
   */
  public function testCollectionVersionedDefinitions(): void {
    $job = $this->job();
    $job->set('context', ['documents' => ['type' => 'string', 'label' => 'Documents', 'multiple' => TRUE]]);
    $invocation = $this->invocation('task_context:documents');
    $job->setChecklistItems(['expand' => $invocation]);
    $job->save();
    $versions = $this->container->get('task_job.version_resolver');
    $six = $versions->createVersion($job, '6');
    $six->save();
    $task = Task::create(['title' => 'Title input', 'job' => $job, 'job_version' => '6']);
    $task->get('context')->set('documents', ['Passport']);
    $task->save();
    foreach ($task->checklist->checklist->getItems() as $item) {
      $item->save();
    }
    $checklist = $task->checklist->checklist;
    $this->container->get('checklist.item_executor')->submit($checklist->getItem('expand'));
    $checklist = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id())->checklist->checklist;
    $child_uuid = $checklist->getItem('expand__member_0__review__consume')->uuid();
    $seven = $versions->createVersion($job, '7');
    $templates = $seven->get('checklist_templates');
    $templates['review']['items']['consume']['label'] = 'Version seven';
    $seven->set('checklist_templates', $templates)->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('task');
    $this->assertSame('Review subject', $storage->loadUnchanged($task->id())->checklist->checklist->getItem('expand__member_0__review__consume')->get('title')->value);
    $dirty = $versions->createDirtyVersion($six);
    $templates = $dirty->get('checklist_templates');
    $templates['review']['items']['consume']['label'] = 'Corrected review';
    $dirty->set('checklist_templates', $templates)->save();
    $fresh = $storage->loadUnchanged($task->id())->checklist->checklist;
    $child = $fresh->getItem('expand__member_0__review__consume');
    $this->assertSame('Corrected review', $child->get('title')->value);
    $this->assertSame($child_uuid, $child->uuid());
    $this->assertTrue($fresh->isItemActive($child));
  }

  /**
   * Missing required inputs block the parent before it records an attempt.
   */
  public function testRequiredInput(): void {
    $job = $this->job();
    $item = $this->invocation();
    $item['handler_configuration']['context_mapping'] = [];
    $job->setChecklistItems(['expand' => $item]);
    $job->save();
    $task = $this->task($job);
    $checklist = $task->checklist->checklist;
    $this->assertNull($this->container->get('checklist.item_executor')->submit($checklist->getItem('expand')));
    $this->assertFalse($checklist->hasItem('expand__template__review__consume'));
  }

  /**
   * Nested invocations inherit their enclosing scope, not a sibling's input.
   */
  public function testNestedTemplates(): void {
    $job = $this->job();
    $templates = $job->get('checklist_templates');
    $templates['inner'] = $templates['review'];
    $templates['review']['items'] = ['nested' => $this->invocation('template_context:subject', 'inner')];
    $job->set('checklist_templates', $templates)->save();
    $task = $this->task($job);
    $checklist = $task->checklist->checklist;
    $this->assertTrue($this->container->get('checklist.processor')->process($checklist));
    $this->assertTrue($checklist->getItem('expand__template__review__nested__template__inner__consume')->isComplete());
    $this->assertSame([['Title input', NULL]], $this->container->get('state')->get('checklist_context_test.runs'));
  }

  /**
   * Cycles and unknown mapping destinations fail before materializing work.
   */
  public function testInvalidExpansion(): void {
    $job = $this->job();
    $templates = $job->get('checklist_templates');
    $templates['review']['items'] = ['again' => $this->invocation()];
    try {
      JobChecklistExpansion::expand($job->getChecklistItems(), $templates);
      $this->fail('Recursive templates must be rejected.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('recursively included', $exception->getMessage());
    }
    $item = $this->invocation();
    $item['handler_configuration']['context_mapping']['checklist:entity'] = 'checklist:entity';
    $this->expectException(\InvalidArgumentException::class);
    JobChecklistExpansion::expand(['expand' => $item], $job->get('checklist_templates'));
  }

  /**
   * Importing delegated children does not manufacture approval; retry is safe.
   */
  public function testDelegationRequiresApproval(): void {
    $job = $this->job();
    $templates = $job->get('checklist_templates');
    $templates['review']['items']['consume']['execution'] = [
      'mode' => 'context',
      'context_mapping' => ['executor' => 'checklist:entity.creator.entity'],
    ];
    $job->setSyncing(TRUE);
    $job->set('checklist_templates', $templates)->save();
    $task = $this->task($job);
    $executor = $this->container->get('checklist.item_executor');
    $parent = $task->checklist->checklist->getItem('expand');
    try {
      $executor->submit($parent);
      $this->fail('Unapproved delegated children must not activate.');
    }
    catch (AccessDeniedHttpException) {
      $fresh = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id())->checklist->checklist;
      $this->assertFalse($fresh->hasItem('expand__template__review__consume'));
    }
    $failed = $this->container->get('checklist.attempt_journal')->latest($parent);
    $this->assertSame(ChecklistAttempt::FAILED, $failed->status);
    $job->setSyncing(FALSE);
    $job->save();
    $retried = $executor->retry($parent, $failed, ChecklistAttempt::FRESH);
    $this->assertSame(ChecklistAttempt::SUCCEEDED, $retried->status);
    $this->assertSame($failed->id, $retried->previous);
    $fresh = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id())->checklist->checklist;
    $this->assertCount(2, $fresh->getItems());
    $this->assertTrue($fresh->isItemActive($fresh->getItem('expand__template__review__consume')));
  }

  /**
   * Collection membership is frozen and children consume independent values.
   */
  public function testCollectionMembership(): void {
    $job = $this->job();
    $job->set('context', ['documents' => ['type' => 'string', 'label' => 'Documents', 'multiple' => TRUE]]);
    $invocation = $this->invocation('task_context:documents');
    $job->setChecklistItems(['expand' => $invocation]);
    $job->save();
    $task = Task::create(['title' => 'Review documents', 'job' => $job]);
    $task->get('context')->set('documents', ['Passport', 'Bank statement']);
    $task->save();
    foreach ($task->checklist->checklist->getItems() as $item) {
      $item->save();
    }
    $checklist = $task->checklist->checklist;
    $this->assertCount(1, $checklist->getItems());
    $this->assertTrue($this->container->get('checklist.processor')->process($checklist));
    $this->assertSame([['Passport', NULL], ['Bank statement', NULL]], $this->container->get('state')->get('checklist_context_test.runs'));
    $this->assertSame(['expand', 'expand__member_0__review__consume', 'expand__member_1__review__consume'], array_keys($checklist->getOrderedItems()));
    $uuid = $checklist->getItem('expand__member_0__review__consume')->uuid();
    $task->get('context')->set('documents', ['New document', 'Bank statement', 'Passport']);
    $task->save();
    $fresh = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id())->checklist->checklist;
    $this->assertCount(3, $fresh->getItems());
    $this->assertSame($uuid, $fresh->getItem('expand__member_0__review__consume')->uuid());
    $this->assertSame(['Passport', 'Bank statement'], $fresh->getItem('expand')->get('outcomes')->get('members_subject')->getValue());
    $this->container->get('checklist.item_executor')->submit($fresh->getItem('expand'));
    $this->assertCount(3, $fresh->getItems());
  }

  /**
   * Empty sets finish without children; entity sets retain references.
   */
  public function testEntityCollection(): void {
    $job = $this->job();
    $job->set('context', ['documents' => ['type' => 'entity:task', 'label' => 'Documents', 'multiple' => TRUE]]);
    $templates = $job->get('checklist_templates');
    $templates['review']['context']['subject']['type'] = 'entity:task';
    $templates['review']['items']['consume']['handler_configuration']['context_mapping']['value'] = 'template_context:subject.title.value';
    $job->set('checklist_templates', $templates);
    $invocation = $this->invocation('task_context:documents');
    $job->setChecklistItems(['expand' => $invocation]);
    $job->save();
    $document = Task::create([
      'title' => 'Passport',
      'description' => 'A document stand-in for the generic entity collection test.',
    ]);
    $document->save();
    $task = Task::create(['title' => 'Document review', 'job' => $job]);
    $task->get('context')->set('documents', [$document]);
    $task->save();
    $checklist = $task->checklist->checklist;
    $parent = $checklist->getItem('expand');
    $parent->save();
    $this->container->get('checklist.item_executor')->submit($parent);
    $document->set('title', 'Updated passport')->save();
    $fresh = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id())->checklist->checklist;
    $this->assertSame($document->id(), $fresh->getItem('expand')->get('outcomes')->get('members_subject')->get(0)->getValue()->id());
    $this->assertTrue($this->container->get('checklist.processor')->process($fresh));
    $this->assertSame([['Updated passport', NULL]], $this->container->get('state')->get('checklist_context_test.runs'));
    $empty = Task::create(['title' => 'No documents', 'job' => $job]);
    $empty->get('context')->set('documents', []);
    $empty->save();
    $empty_checklist = $empty->checklist->checklist;
    $empty_checklist->getItem('expand')->save();
    $this->assertTrue($this->container->get('checklist.processor')->process($empty_checklist));
    $this->assertCount(1, $empty_checklist->getItems());
  }

  /**
   * A failed oversized expansion rolls back every child and can retry safely.
   */
  public function testCollectionRollback(): void {
    $job = $this->job();
    $job->set('context', ['documents' => ['type' => 'string', 'label' => 'Documents', 'multiple' => TRUE]]);
    $invocation = $this->invocation('task_context:documents');
    $job->setChecklistItems(['expand' => $invocation]);
    $templates = $job->get('checklist_templates');
    $templates['review']['items']['second'] = $templates['review']['items']['consume'];
    $job->set('checklist_templates', $templates)->save();
    $task = Task::create(['title' => 'Too many reviews', 'job' => $job]);
    $task->get('context')->set('documents', array_fill(0, 501, 'Document'));
    $task->save();
    $parent = $task->checklist->checklist->getItem('expand');
    $parent->save();
    $executor = $this->container->get('checklist.item_executor');
    try {
      $executor->submit($parent);
      $this->fail('Oversized expansion must fail.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('1,000', $exception->getMessage());
    }
    $storage = $this->container->get('entity_type.manager')->getStorage('task');
    $fresh_task = $storage->loadUnchanged($task->id());
    $this->assertCount(1, $fresh_task->checklist->checklist->getItems());
    $parent = $fresh_task->checklist->checklist->getItem('expand');
    $failed = $this->container->get('checklist.attempt_journal')->latest($parent);
    $this->assertSame(ChecklistAttempt::FAILED, $failed->status);
    $fresh_task->get('context')->set('documents', ['One document']);
    $fresh_task->save();
    $parent = $storage->loadUnchanged($task->id())->checklist->checklist->getItem('expand');
    $this->assertSame(ChecklistAttempt::SUCCEEDED, $executor->retry($parent, $failed, ChecklistAttempt::FRESH)->status);
    $this->assertCount(3, $storage->loadUnchanged($task->id())->checklist->checklist->getItems());
  }

  /**
   * Nested collection invocations keep separate identities and local inputs.
   */
  public function testNestedCollections(): void {
    $job = $this->job();
    $job->set('context', ['documents' => ['type' => 'string', 'label' => 'Documents', 'multiple' => TRUE]]);
    $invocation = $this->invocation('task_context:documents');
    $job->setChecklistItems(['expand' => $invocation]);
    $templates = $job->get('checklist_templates');
    $templates['inner'] = $templates['review'];
    $nested = $invocation;
    $nested['handler_configuration']['template'] = 'inner';
    $templates['review']['items'] = ['nested' => $nested];
    $job->set('checklist_templates', $templates)->save();
    $task = Task::create(['title' => 'Nested collections', 'job' => $job]);
    $task->get('context')->set('documents', ['A', 'B']);
    $task->save();
    $checklist = $task->checklist->checklist;
    $checklist->getItem('expand')->save();
    $this->assertTrue($this->container->get('checklist.processor')->process($checklist));
    $this->assertCount(7, $checklist->getItems());
    $this->assertSame([['A', NULL], ['B', NULL], ['A', NULL], ['B', NULL]], $this->container->get('state')->get('checklist_context_test.runs'));
  }

  /**
   * Single inputs expand Cartesian products; list inputs remain whole lists.
   */
  public function testCartesianMappings(): void {
    $job = $this->job();
    $job->set('context', [
      'documents' => ['type' => 'string', 'label' => 'Documents', 'multiple' => TRUE],
      'reviewers' => ['type' => 'string', 'label' => 'Reviewers', 'multiple' => TRUE],
    ]);
    $invocation = $this->invocation('task_context:documents');
    $invocation['handler_configuration']['context_mapping'] += [
      'template_context:reviewer' => 'task_context:reviewers',
      'template_context:all_documents' => 'task_context:documents',
    ];
    $job->setChecklistItems(['expand' => $invocation]);
    $templates = $job->get('checklist_templates');
    $templates['review']['context']['reviewer'] = ['type' => 'string', 'label' => 'Reviewer'];
    $templates['review']['context']['all_documents'] = [
      'type' => 'string',
      'label' => 'All documents',
      'multiple' => TRUE,
    ];
    $templates['review']['items']['consume']['handler_configuration']['context_mapping']['optional'] = 'template_context:reviewer';
    $job->set('checklist_templates', $templates)->save();
    $task = Task::create(['title' => 'Every document and reviewer', 'job' => $job]);
    $task->get('context')->set('documents', ['Passport', 'Statement']);
    $task->get('context')->set('reviewers', ['Alex', 'Blair', 'Casey']);
    $task->save();
    $checklist = $task->checklist->checklist;
    $checklist->getItem('expand')->save();
    $this->assertTrue($this->container->get('checklist.processor')->process($checklist));
    $this->assertCount(7, $checklist->getItems());
    $this->assertSame([
      ['Passport', 'Alex'], ['Passport', 'Blair'], ['Passport', 'Casey'],
      ['Statement', 'Alex'], ['Statement', 'Blair'], ['Statement', 'Casey'],
    ], $this->container->get('state')->get('checklist_context_test.runs'));
    $child = $checklist->getItem('expand__member_3__review__consume');
    $contexts = $this->container->get('checklist.context_collector')->collectRuntimeContexts($checklist, $child);
    $this->assertSame(['Passport', 'Statement'], $contexts['template_context:all_documents']->getContextValue());
    $this->assertSame(['subject', 'reviewer'], $checklist->getItem('expand')->get('outcomes')->get('iterated')->getValue());
    $empty = Task::create(['title' => 'No reviewers', 'job' => $job]);
    $empty->get('context')->set('documents', ['Passport']);
    $empty->get('context')->set('reviewers', []);
    $empty->save();
    $empty_checklist = $empty->checklist->checklist;
    $empty_checklist->getItem('expand')->save();
    $this->assertTrue($this->container->get('checklist.processor')->process($empty_checklist));
    $this->assertCount(1, $empty_checklist->getItems());
  }

}
