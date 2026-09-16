<?php

namespace Drupal\Tests\task_job\Kernel;

use Drupal\exec_environment\Environment;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Test the job trigger handler.
 */
class EnvironmentJobTriggerHandlerTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'task', 'task_job', 'entity_template', 'exec_environment', 'options',
    'exec_environment_config_test', 'user', 'task_job_test', 'text', 'datetime',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('system', ['key_value']);
    $this->installSchema('task_job', 'task_job_trigger_index');
  }

  /**
   * Test that the handle trigger method works correctly.
   */
  public function testHandleTriggerMethod() {
    /** @var \Drupal\Core\Entity\EntityStorageInterface $job_storage */
    $job_storage = $this->container->get('entity_type.manager')->getStorage('task_job');
    $job = $job_storage->create([
      'id' => 'make_tea',
      'label' => 'Make Tea',
      'default_checklist' => [],
      'triggers' => [
        'test_trigger' => [
          'id' => 'test_trigger',
          'key' => 'test_trigger',
          'template' => [
            'id' => 'default',
            'label' => 'Template',
            'uuid' => 'default',
            'components' => [
              'title' => [
                'id' => 'field.widget_input:task.title',
                'uuid' => 'title',
                'field_type' => 'string',
                'value' => [
                  ['value' => 'Make Tea'],
                ],
              ],
            ],
            'conditions' => [],
            'description' => '',
            'priority' => 0,
          ],
        ],
      ],
    ]);
    $job->save();

    /** @var \Drupal\exec_environment\EnvironmentStackInterface $environment_stack */
    $environment_stack = $this->container->get('environment_stack');
    // Collection has the job with the trigger that sets the title to Make Good
    // Tea instead of Make Tea.
    $collection_name = $this->randomMachineName();
    $environment = (new Environment())->addComponent(
      $this->container->get('plugin.manager.exec_environment_component')
        ->createInstance('test_named_config_collection', ['collection' => $collection_name])
    );
    $environment_stack->applyEnvironment($environment);
    $triggers = $job->get('triggers');
    $triggers['test_trigger']['template']['components']['title']['value'][0]['value'] = 'Make Good Tea';
    $job = $job_storage->loadUnchanged($job->id());
    $job->set('triggers', $triggers)->save();
    $environment_stack->resetEnvironment();

    // Collection2 has the job with no triggers in it at all.
    $collection_name2 = $this->randomMachineName();
    $environment = (new Environment())->addComponent(
      $this->container->get('plugin.manager.exec_environment_component')
        ->createInstance('test_named_config_collection', ['collection' => $collection_name2])
    );
    $environment_stack->applyEnvironment($environment);
    $job = $job_storage->loadUnchanged($job->id());
    $job->set('triggers', [])->save();
    $environment_stack->resetEnvironment();

    // Handle the trigger with no environment being detected, get a task with
    // Make tea as the title.
    /** @var \Drupal\task_job\Plugin\JobTrigger\JobTriggerManagerInterface $manager */
    $manager = $this->container->get('plugin.manager.task_job.trigger');

    $tasks = $manager->handleTrigger('test_trigger', [], FALSE);
    $this->assertCount(1, $tasks);
    $task = reset($tasks);
    $this->assertEquals('Make Tea', $task->title->value);

    $this->container->get('state')->set('task_job_test.handle_trigger_collection', $collection_name);
    $tasks = $manager->handleTrigger('test_trigger', [], FALSE);
    $this->assertCount(1, $tasks);
    $task = reset($tasks);
    $this->assertEquals('Make Good Tea', $task->title->value);

    $this->container->get('state')->set('task_job_test.handle_trigger_collection', $collection_name2);
    $tasks = $manager->handleTrigger('test_trigger', [], FALSE);
    $this->assertCount(0, $tasks);
  }

}
