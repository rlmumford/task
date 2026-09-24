<?php

namespace Drupal\Tests\task_job\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

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

    $this->assertSame('Version 6 review', $task->checklist->checklist->getItem('review')->label());

    $version_seven = $resolver->createVersion($base, '7');
    $version_seven->set('default_checklist', [
      'review' => [
        'label' => 'Version 7 review',
        'handler' => 'simply_checkable',
        'handler_configuration' => [],
      ],
    ]);
    $version_seven->save();

    $task = Task::load($task->id());
    $this->assertSame('Version 6 review', $task->checklist->checklist->getItem('review')->label());

    $dirty = $resolver->createDirtyVersion($version_six);
    $dirty->set('default_checklist', [
      'review' => [
        'label' => 'Version 6 dirty review',
        'handler' => 'simply_checkable',
        'handler_configuration' => [],
      ],
    ]);
    $dirty->save();

    $task = Task::load($task->id());
    $this->assertSame('Version 6 dirty review', $task->checklist->checklist->getItem('review')->label());
  }

}
