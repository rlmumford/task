<?php

namespace Drupal\Tests\task_job\Kernel;

use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Test entity operations.
 */
class EntityOperationJobTriggerTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'task', 'task_job', 'entity_template', 'entity_template_ui', 'entity',
    'typed_data', 'options', 'datetime',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installSchema('task_job', 'task_job_trigger_index');
    $this->installEntitySchema('task_job');
    $this->installEntitySchema('task');
  }

  /**
   * Test that a task gets created on an entity trigger.
   */
  public function testUserInsertJobTrigger() {
    // Create the job.
    $job_storage = $this->entityTypeManager->getStorage('task_job');
    $job = $job_storage->create([
      'label' => 'Make Tea',
      'id' => 'make_tea',
      'default_checklist' => [],
      'resources' => [],
      'triggers' => [
        'user__insert' => [
          'id' => 'entity_op:user.insert',
          'key' => 'user__insert',
          'template' => [
            'id' => 'default',
            'label' => 'Template',
            'uuid' => 'default',
            'components' => [
              'title' => [
                'field_type' => 'string',
                'id' => 'field.widget_input:task.title',
                'uuid' => 'title',
                'value' => [
                  ['value' => 'Make a cup of tea for {{ user.name }}'],
                ],
              ],
            ],
            'conditions' => [],
          ],
          'label' => 'User Insert',
        ],
      ] ,
    ]);
    $job->save();

    $this->entityTypeManager->getStorage('user')->create([
      'name' => 'test_user',
      'mail' => 'test@test.com',
    ])->save();

    $task_storage = $this->entityTypeManager->getStorage('task');
    $ids = $task_storage->getQuery()
      ->condition('job', $job->id())
      ->accessCheck(FALSE)
      ->execute();
    $this->assertEquals(1, count($ids), 'A single task has been created.');
    $task = $task_storage->load(reset($ids));
    $this->assertEquals('Make a cup of tea for test_user', $task->title->value, 'The task title has been applied.');

    $job->disable()->save();
    $this->entityTypeManager->getStorage('user')->create([
      'name' => 'test_user2',
      'mail' => 'test2@test.com',
    ])->save();
    $ids = $task_storage->getQuery()
      ->condition('job', $job->id())
      ->accessCheck(FALSE)
      ->execute();
    $this->assertEquals(1, count($ids), 'Another task has not been created.');
  }

}
