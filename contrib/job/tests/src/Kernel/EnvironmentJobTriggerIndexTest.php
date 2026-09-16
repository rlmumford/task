<?php

namespace Drupal\Tests\task_job\Kernel;

use Drupal\exec_environment\Environment;
use Drupal\KernelTests\KernelTestBase;

/**
 * Test the job trigger index.
 */
class EnvironmentJobTriggerIndexTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'task', 'task_job', 'entity_template', 'exec_environment',
    'exec_environment_config_test', 'user', 'options',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installSchema('task_job', 'task_job_trigger_index');
    $this->installEntitySchema('user');
  }

  /**
   * Test that the index works correctly when an environment removes a trigger.
   */
  public function testEnvironmentRemovedTrigger() {
    $job_storage = $this->container->get('entity_type.manager')->getStorage('task_job');
    $job = $job_storage->create([
      'id' => 'make_tea',
      'label' => 'Make Tea',
      'default_checklist' => [],
      'triggers' => [
        'manual' => [
          'id' => 'manual',
          'key' => 'manual',
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

    /** @var \Drupal\task_job\Plugin\JobTrigger\JobTriggerManagerInterface $trigger_manager */
    $trigger_manager = $this->container->get('plugin.manager.task_job.trigger');
    $triggers = $trigger_manager->getTriggers('manual');

    $this->assertCount(1, $triggers);

    /** @var \Drupal\exec_environment\EnvironmentStackInterface $environment_stack */
    $environment_stack = $this->container->get('environment_stack');
    $collection_name = $this->randomMachineName();
    $environment = (new Environment())->addComponent(
      $this->container->get('plugin.manager.exec_environment_component')
        ->createInstance('test_named_config_collection', ['collection' => $collection_name])
    );
    $environment_stack->applyEnvironment($environment);

    $this->assertCount(1, $trigger_manager->getTriggers('manual'));

    $job->set('triggers', [])->save();

    $this->assertCount(0, $trigger_manager->getTriggers('manual'));

    $environment_stack->resetEnvironment();
    $job = $job_storage->load($job->id());
    $this->assertCount(1, $job->get('triggers'));
    $this->assertCount(1, $trigger_manager->getTriggers('manual'));

    $environment_stack->applyEnvironment($environment);
    $job = $job_storage->load($job->id());
    $this->assertEmpty($job->get('triggers'));
    $this->assertCount(0, $trigger_manager->getTriggers('manual'));
  }

  /**
   * Test that the triggers detection works when an environment adds a trigger.
   */
  public function testAdditionalEnvironmentTrigger() {
    $job_storage = $this->container->get('entity_type.manager')->getStorage('task_job');
    $job = $job_storage->create([
      'id' => 'make_tea',
      'label' => 'Make Tea',
      'default_checklist' => [],
      'triggers' => [],
    ]);
    $job->save();

    /** @var \Drupal\task_job\Plugin\JobTrigger\JobTriggerManagerInterface $trigger_manager */
    $trigger_manager = $this->container->get('plugin.manager.task_job.trigger');
    $triggers = $trigger_manager->getTriggers('manual');

    $this->assertCount(0, $triggers);

    /** @var \Drupal\exec_environment\EnvironmentStackInterface $environment_stack */
    $environment_stack = $this->container->get('environment_stack');
    $collection_name = $this->randomMachineName();
    $environment = (new Environment())->addComponent(
      $this->container->get('plugin.manager.exec_environment_component')
        ->createInstance('test_named_config_collection', ['collection' => $collection_name])
    );
    $environment_stack->applyEnvironment($environment);

    $this->assertCount(0, $trigger_manager->getTriggers('manual'));

    $job->set('triggers', [
      'manual' => [
        'id' => 'manual',
        'key' => 'manual',
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
    ])->save();

    $this->assertCount(1, $trigger_manager->getTriggers('manual'));

    $environment_stack->resetEnvironment();
    $job = $job_storage->load($job->id());
    $this->assertEmpty($job->get('triggers'));
    $this->assertCount(0, $trigger_manager->getTriggers('manual'));

    $environment_stack->applyEnvironment($environment);
    $job = $job_storage->load($job->id());
    $this->assertCount(1, $job->get('triggers'));
    $this->assertCount(1, $trigger_manager->getTriggers('manual'));
  }

}
