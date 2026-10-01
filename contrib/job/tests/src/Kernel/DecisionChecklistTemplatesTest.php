<?php

namespace Drupal\Tests\task_job\Kernel;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\task_job\JobConfigurationChecklist;
use Drupal\user\Entity\User;

/**
 * Tests decision branches through real checklist operations and persistence.
 *
 * @group task_job
 */
class DecisionChecklistTemplatesTest extends KernelTestBase {

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
    foreach (['user', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user']);
    $user = User::create(['name' => 'Administrator', 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Creates a job with two independent instances of the same template.
   */
  protected function createJob(): Job {
    $job = Job::create([
      'id' => 'documents',
      'label' => 'Document review',
      'default_checklist' => [
        'review' => [
          'label' => 'Review documents',
          'handler' => 'decision',
          'handler_configuration' => [
            'question' => 'What is needed?',
            'options' => [
              'more' => ['label' => 'More documents', 'template' => 'documents'],
              'other' => ['label' => 'Other documents', 'template' => 'documents'],
              'none' => ['label' => 'Nothing else'],
            ],
          ],
        ],
        'later' => [
          'label' => 'Use the generated outcome',
          'handler' => 'context_consumer',
          'handler_configuration' => [
            'context_mapping' => ['value' => 'item:review__more__documents__proof:decision'],
          ],
        ],
      ],
      'checklist_templates' => [
        'documents' => [
          'label' => 'Collect documents',
          'items' => [
            'proof' => [
              'label' => 'Approve proof',
              'handler' => 'decision',
              'handler_configuration' => [
                'question' => 'Is the proof sufficient?',
                'options' => ['accepted' => ['label' => 'Accept']],
              ],
            ],
            'use_proof' => [
              'label' => 'Record approval',
              'handler' => 'context_consumer',
              'handler_configuration' => [
                'context_mapping' => ['value' => 'item:proof:decision'],
                'conditions' => [
                  'actionability' => [
                    'id' => 'condition_string',
                    'condition_string' => 'items.proof.outcomes.decision == "accepted"',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);
    $job->save();
    return $job;
  }

  /**
   * API choices expose work and scoped outcomes, preserving branch history.
   */
  public function testBranchLifecycle(): void {
    $job = $this->createJob();
    $collector = $this->container->get('checklist.context_collector');
    $contexts = $collector->collectConfigContexts(JobConfigurationChecklist::createFromJob($job));
    $name = 'review__more__documents__proof';
    $this->assertArrayHasKey('item:' . $name . ':decision', $contexts);
    $task = Task::create(['title' => 'Review evidence', 'job' => $job]);
    $task->save();
    $checklist = $task->checklist->checklist;
    $child = $checklist->getItem($name);
    $other = $checklist->getItem('review__other__documents__proof');
    $this->assertFalse($child->isApplicable());
    $this->assertSame([], $this->container->get('checklist.action_resource_collector')->collect($checklist));
    $dispatcher = $this->container->get('checklist.action_operation_dispatcher');
    $this->assertSame([], $dispatcher->discover($checklist, $name));
    $dispatcher->execute($checklist, 'review', 'choose', ['choice' => 'more']);
    $this->assertTrue($child->isApplicable());
    $this->assertFalse($other->isApplicable());
    $this->assertFalse($checklist->isCompletable());
    $dispatcher->execute($checklist, $name, 'choose', ['choice' => 'accepted']);
    $id = $child->id();
    $completed = (int) $child->get('completed')->value;
    $consumer = $checklist->getItem('review__more__documents__use_proof');
    $preparer = $this->container->get('checklist.context_preparer');
    $this->assertTrue($preparer->prepare($checklist, $consumer));
    $this->assertSame('accepted', $consumer->getHandler()->getContextValue('value'));
    $this->assertTrue($consumer->isActionable());
    $this->assertTrue($preparer->prepare($checklist, $checklist->getItem('later')));
    $this->assertSame('accepted', $checklist->getItem('later')->getHandler()->getContextValue('value'));

    $storage = $this->container->get('entity_type.manager')->getStorage('task');
    $task = $storage->loadUnchanged($task->id());
    $checklist = $task->checklist->checklist;
    $this->assertSame($id, $checklist->getItem($name)->id());
    // Simulate an explicit workflow revising its decision. Completion is not
    // silently undone and no reset/retry UI is introduced by branch expansion.
    $parent = $checklist->getItem('review');
    $parent->setOutcome('decision', 'other')->save();
    $child = $checklist->getItem($name);
    $this->assertFalse($child->isApplicable());
    $this->assertTrue($child->isComplete());
    $this->assertSame($completed, (int) $child->get('completed')->value);
    $this->assertTrue($checklist->getItem('review__other__documents__proof')->isApplicable());
    $this->assertFalse($collector->collectRuntimeContexts($checklist)['item:' . $name . ':decision']->hasContextValue());
    $this->assertFalse($preparer->prepare($checklist, $checklist->getItem('later')));
    $parent->setOutcome('decision', 'more')->save();
    $this->assertTrue($child->isApplicable());
    $this->assertSame($id, $child->id());
    $this->assertSame(2, (int) $this->container->get('entity_type.manager')->getStorage('checklist_item')->getQuery()->accessCheck(FALSE)->count()->execute());

    // Removing a definition archives work; restoring it reuses that work.
    $items = $job->getChecklistItems();
    unset($items['review']['handler_configuration']['options']['more']['template']);
    $job->setChecklistItems($items);
    $job->save();
    $reloaded = $storage->loadUnchanged($task->id())->checklist->checklist;
    $this->assertSame($id, $reloaded->getItem($name)->id());
    $this->assertFalse($reloaded->isItemActive($reloaded->getItem($name)));
    $items['review']['handler_configuration']['options']['more']['template'] = 'documents';
    $job->setChecklistItems($items);
    $job->save();
    $restored = $storage->loadUnchanged($task->id())->checklist->checklist;
    $this->assertSame($id, $restored->getItem($name)->id());
    $this->assertTrue($restored->isItemActive($restored->getItem($name)));

  }

  /**
   * Branch input mapping uses the shared handler, including property selectors.
   */
  public function testBranchContextMapping(): void {
    $job = $this->createJob();
    $job->addContextDefinition('subject', ContextDefinition::create('string')->setLabel('Subject')->setRequired(FALSE));
    $items = $job->getChecklistItems();
    $items['review']['handler_configuration']['options']['more']['context_mapping'] = ['task_context:subject' => 'checklist:entity.title.value'];
    $job->setChecklistItems($items);
    $job->save();
    $task = Task::create(['title' => 'Mapped title', 'job' => $job]);
    $task->save();
    $checklist = $task->checklist->checklist;
    $this->container->get('checklist.action_operation_dispatcher')->execute($checklist, 'review', 'choose', ['choice' => 'more']);
    $contexts = $this->container->get('checklist.context_collector')->collectRuntimeContexts($checklist, $checklist->getItem('review__more__documents__proof'));
    $this->assertSame('Mapped title', $contexts['task_context:subject']->getContextValue());
  }

  /**
   * Recursive and missing templates fail instead of completing partial work.
   */
  public function testInvalidTemplates(): void {
    $job = $this->createJob();
    $templates = $job->get('checklist_templates');
    $templates['documents']['items'] = $job->getChecklistItems();
    $job->set('checklist_templates', $templates);
    try {
      $job->getExpandedChecklistItems();
      $this->fail('Recursive templates must be rejected.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('recursively included', $exception->getMessage());
    }
    $job->set('checklist_templates', []);
    $this->expectException(\InvalidArgumentException::class);
    $job->getExpandedChecklistItems();
  }

  /**
   * A chosen automatic branch can finish in the same checklist pass.
   */
  public function testAutomaticBranchProcessing(): void {
    $job = $this->createJob();
    $items = $job->getChecklistItems();
    unset($items['later']);
    $job->setChecklistItems($items);
    $job->set('checklist_templates', [
      'documents' => [
        'label' => 'Automatic follow-up',
        'items' => [
          'produce' => ['label' => 'Produce', 'handler' => 'context_producer', 'handler_configuration' => []],
          'consume' => [
            'label' => 'Consume',
            'handler' => 'context_consumer',
            'handler_configuration' => ['context_mapping' => ['value' => 'item:produce:value']],
          ],
        ],
      ],
    ])->save();
    $task = Task::create(['title' => 'Automatic branch', 'job' => $job]);
    $task->save();
    $checklist = $task->checklist->checklist;
    $this->container->get('checklist.action_operation_dispatcher')->execute($checklist, 'review', 'choose', ['choice' => 'more']);
    $this->container->get('checklist.processor')->process($checklist);
    $this->assertTrue($checklist->getItem('review__more__documents__consume')->isComplete());
    $this->assertTrue($checklist->isCompletable());
    $this->assertSame([['Produced', NULL]], $this->container->get('state')->get('checklist_context_test.runs'));
    $this->assertFalse($checklist->getItem('review__other__documents__consume')->isComplete());
  }

  /**
   * A nested choice requires every ancestor and keeps its own scoped identity.
   */
  public function testNestedBranches(): void {
    $job = $this->createJob();
    $templates = $job->get('checklist_templates');
    $templates['documents']['items']['proof']['handler_configuration']['options']['accepted']['template'] = 'finish';
    $templates['finish'] = [
      'label' => 'Finish',
      'items' => [
        'record' => ['label' => 'Record', 'handler' => 'simply_checkable', 'handler_configuration' => []],
      ],
    ];
    $job->set('checklist_templates', $templates)->save();
    $task = Task::create(['title' => 'Nested branch', 'job' => $job]);
    $task->save();
    $checklist = $task->checklist->checklist;
    $name = 'review__more__documents__proof__accepted__finish__record';
    $nested = $checklist->getItem($name);
    $this->assertFalse($nested->isApplicable());
    $dispatcher = $this->container->get('checklist.action_operation_dispatcher');
    $dispatcher->execute($checklist, 'review', 'choose', ['choice' => 'more']);
    $this->assertFalse($nested->isApplicable());
    $dispatcher->execute($checklist, 'review__more__documents__proof', 'choose', ['choice' => 'accepted']);
    $this->assertTrue($nested->isApplicable());
    $checklist->getItem('review')->setOutcome('decision', 'none')->save();
    $this->assertFalse($nested->isApplicable());
  }

}
