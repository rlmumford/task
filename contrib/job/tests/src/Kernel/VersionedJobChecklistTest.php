<?php

namespace Drupal\Tests\task_job\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\task_job\JobConfigurationChecklist;

/**
 * Tests task checklist resolution across clean and dirty job versions.
 */
class VersionedJobChecklistTest extends KernelTestBase {

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
    foreach (['user', 'task', 'checklist_item'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
  }

  /**
   * A task follows its named version and then its dirty working copy.
   */
  public function testTaskChecklistUsesVersionAndDirtyOverlay(): void {
    $base = Job::create([
      'id' => 'employment_support',
      'label' => 'Employment support',
      'default_checklist' => [
        'review' => [
          'label' => 'Base review',
          'handler' => 'simply_checkable',
          'handler_configuration' => [],
        ],
      ],
    ]);
    $base->save();

    $resolver = $this->container->get('task_job.version_resolver');
    $version_six = $resolver->createVersion($base, '6');
    $version_six->set('default_checklist', [
      'review' => [
        'label' => 'Version 6 review',
        'handler' => 'simply_checkable',
        'handler_configuration' => [],
      ],
    ]);
    $version_six->save();

    $task = Task::create([
      'title' => 'Employment support task',
      'job' => $base,
      'job_version' => '6',
    ]);
    $task->save();

    $this->assertSame('Version 6 review', $task->checklist->checklist->getItem('review')->get('title')->value);

    $version_seven = $resolver->createVersion($base, '7');
    $version_seven->set('default_checklist', [
      'review' => [
        'label' => 'Version 7 review',
        'handler' => 'simply_checkable',
        'handler_configuration' => [],
      ],
    ]);
    $version_seven->save();

    $task = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id());
    $this->assertSame('Version 6 review', $task->checklist->checklist->getItem('review')->get('title')->value);

    $dirty = $resolver->createDirtyVersion($version_six);
    $dirty->set('default_checklist', [
      'review' => [
        'label' => 'Version 6 dirty review',
        'handler' => 'simply_checkable',
        'handler_configuration' => [],
      ],
    ]);
    $dirty->save();

    $task = $this->container->get('entity_type.manager')->getStorage('task')->loadUnchanged($task->id());
    $this->assertSame('Version 6 dirty review', $task->checklist->checklist->getItem('review')->get('title')->value);
  }

  /**
   * Static template items follow version resolution without losing stored work.
   */
  public function testNamedChecklistTemplates(): void {
    $job = Job::create([
      'id' => 'templates',
      'label' => 'Template job',
      'checklist_templates' => [
        'appointment' => [
          'label' => 'Appointment preparation',
          'items' => [
            'confirm' => [
              'name' => 'confirm',
              'label' => 'Confirm appointment',
              'handler' => 'simply_checkable',
              'handler_configuration' => [],
            ],
          ],
        ],
      ],
      'checklist_includes' => ['appointment'],
    ]);
    $job->save();
    $resolver = $this->container->get('task_job.version_resolver');
    $six = $resolver->createVersion($job, '6');
    $six->save();
    $task = Task::create(['title' => 'Preparation', 'job' => $job, 'job_version' => '6']);
    $task->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('task');
    $this->assertSame(['confirm'], array_keys($task->checklist->checklist->getItems()));
    $this->assertSame('Confirm appointment', $task->checklist->checklist->getItem('confirm')->get('title')->value);
    $seven = $resolver->createVersion($job, '7');
    $items = $seven->getChecklistItems('appointment');
    $items['confirm']['label'] = 'Version seven';
    $seven->setChecklistItems($items, 'appointment');
    $seven->save();
    $task = $storage->loadUnchanged($task->id());
    $this->assertSame('Confirm appointment', $task->checklist->checklist->getItem('confirm')->get('title')->value);
    $dirty = $resolver->createDirtyVersion($six);
    $items['confirm']['label'] = 'Saved version six fix';
    $dirty->setChecklistItems($items, 'appointment');
    $dirty->save();
    $task = $storage->loadUnchanged($task->id());
    $item = $task->checklist->checklist->getItem('confirm');
    $this->assertSame('Saved version six fix', $item->get('title')->value);
    $item->setComplete();
    $item->save();
    $id = $item->id();
    $task = $storage->loadUnchanged($task->id());
    $this->assertSame(['confirm'], array_keys($task->checklist->checklist->getItems()));
    $this->assertSame($id, $task->checklist->checklist->getItem('confirm')->id());
    $this->assertTrue($task->checklist->checklist->getItem('confirm')->isComplete());
    $dirty->set('checklist_includes', []);
    $dirty->save();
    $task = $storage->loadUnchanged($task->id());
    $this->assertSame($id, $task->checklist->checklist->getItem('confirm')->id());
    $this->assertTrue($task->checklist->checklist->getItem('confirm')->isComplete());
    $this->assertSame(['confirm'], array_keys($six->getExpandedChecklistItems()));
    $this->assertContains('checklist', $job->getDependencies()['module']);
    // Missing references and collisions cannot become an empty complete list.
    $job->set('checklist_includes', ['missing']);
    try {
      $job->getExpandedChecklistItems();
      $this->fail('Missing templates must fail explicitly.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('missing', $exception->getMessage());
    }
    $invalid = $this->container->get('plugin.manager.checklist_type')->createInstance('job', ['job' => $job]);
    $checklist = $invalid->getChecklist(Task::create(['title' => 'Invalid configuration']), 'checklist');
    for ($attempt = 0; $attempt < 2; $attempt++) {
      try {
        $checklist->getItems();
        $this->fail('A failed expansion must not cache an empty checklist.');
      }
      catch (\InvalidArgumentException $exception) {
        $this->assertStringContainsString('missing', $exception->getMessage());
      }
    }
    $job->set('checklist_includes', ['appointment']);
    $job->setChecklistItems($job->getChecklistItems('appointment'));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('occurs more than once');
    $job->getExpandedChecklistItems();
  }

  /**
   * Template outcomes are available to later item configuration.
   */
  public function testTemplateOutcomeContexts(): void {
    $job = Job::create([
      'id' => 'outcome_templates',
      'label' => 'Template outcomes',
      'checklist_templates' => [
        'review' => [
          'label' => 'Review',
          'items' => [
            'decision' => [
              'label' => 'Approve the document',
              'handler' => 'decision',
              'handler_configuration' => [
                'options' => ['approve' => ['label' => 'Approve']],
              ],
            ],
          ],
        ],
      ],
    ]);
    $collector = $this->container->get('checklist.context_collector');
    $contexts = $collector->collectConfigContexts(JobConfigurationChecklist::createFromJob($job));
    $this->assertArrayNotHasKey('item:decision:decision', $contexts);
    $contexts = $collector->collectConfigContexts(JobConfigurationChecklist::createFromJob($job, NULL, 'review'));
    $this->assertSame('string', $contexts['item:decision:decision']->getContextDefinition()->getDataType());
    $job->set('checklist_includes', ['review']);
    $contexts = $collector->collectConfigContexts(JobConfigurationChecklist::createFromJob($job));
    $this->assertArrayHasKey('item:decision:decision', $contexts);
    $this->assertArrayHasKey('item:decision:reason', $contexts);
  }

}
