<?php

namespace Drupal\Tests\task\Kernel;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\task_dependency\Event\EntityReplacementEvent;
use Drupal\Core\Form\FormState;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;

/**
 * Tests task-creation templates and shared HTML/API dependency editing.
 *
 * @group task
 */
class TaskDependencyIntegrationTest extends TaskDependencyTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity',
    'task', 'task_dependency', 'entity_test', 'task_dependency_template',
    'task_dependency_flexiform', 'task_dependency_job', 'task_job',
    'entity_template', 'typed_data', 'typed_data_plus', 'typed_data_context_assignment',
    'ctools', 'token', 'flexiform', 'field_ui', 'views', 'checklist',
    'plugin_reference', 'typed_data_reference', 'inline_entity_form', 'task_checklist', 'task_context', 'checklist_state_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('task_job', ['task_job_trigger_index']);
    $this->installSchema('task_checklist', ['task_checklist_request']);
    $this->installSchema('checklist', [
      'checklist_attempt',
      'checklist_attempt_head',
      'checklist_attempt_event',
    ]);
    $this->installEntitySchema('checklist_item');
  }

  /**
   * A document request trigger creates a task waiting for that document.
   */
  public function testTriggerCreatesDependency(): void {
    $job = Job::create([
      'id' => 'document_review',
      'label' => 'Review approved document',
      'triggers' => [
        'requested' => [
          'id' => 'entity_op:entity_test.insert',
          'key' => 'requested',
          'template' => [
            'id' => 'default',
            'uuid' => 'requested',
            'label' => 'Review',
            'components' => [
              'dependency' => [
                'id' => 'task_dependency',
                'uuid' => 'dependency',
                'trigger' => 'entity.state:entity_test',
                'context_mapping' => ['entity' => 'entity_test'],
                'field' => 'name',
                'property' => 'value',
                'value' => 'approved',
                'action' => 'activate',
              ],
            ],
          ],
        ],
      ],
    ]);
    $job->save();
    $document = EntityTest::create(['name' => 'requested']);
    $document->save();
    $tasks = $this->container->get('entity_type.manager')->getStorage('task')->loadByProperties(['job' => $job->id()]);
    $this->assertCount(1, $tasks);
    $task = reset($tasks);
    $this->assertSame('waiting', $task->status->value);
    $this->assertSame($document->uuid(), $task->event_dependencies->entity->bindings->first()->entity_uuid);
    $document->set('name', 'approved')->save();
    $this->drain();
    $this->assertSame('active', $this->fresh($task)->status->value);
  }

  /**
   * Replacement actions move only outstanding work belonging to their job.
   */
  public function testReplacementAction(): void {
    $job = Job::create([
      'id' => 'follow_replacement',
      'label' => 'Follow replacement',
      'triggers' => [
        'replaced' => [
          'id' => 'entity.replaced:entity_test',
          'key' => 'replaced',
          'action' => [
            'plugin' => 'retarget_dependencies',
            'configuration' => [
              'dependency_trigger' => 'entity.state:entity_test',
              'dependency_action' => 'activate',
              'context_mapping' => [
                'original' => 'original',
                'replacement' => 'replacement',
              ],
            ],
          ],
          'template' => ['id' => 'default', 'uuid' => 'replacement', 'components' => []],
        ],
      ],
    ]);
    $job->save();
    $other_job = Job::create(['id' => 'other_job', 'label' => 'Other job']);
    $other_job->save();
    $original = EntityTest::create(['name' => 'draft']);
    $original->save();
    $replacement = EntityTest::create(['name' => 'draft']);
    $replacement->save();
    $manager = $this->container->get('task_dependency.manager');
    $tasks = [];
    foreach (['move', 'other', 'matched', 'terminal', 'invalidate'] as $name) {
      $task = Task::create(['title' => $name, 'job' => $name === 'other' ? $other_job : $job]);
      $dependency = $manager->create($task, 'entity.state:entity_test', [
        'field' => 'name',
        'value' => 'approved',
      ], $name === 'invalidate' ? 'invalidate' : 'activate', $original);
      $task->event_dependencies[] = ['entity' => $dependency];
      $task->save();
      if ($name === 'matched') {
        $dependency->set('met', TRUE)->save();
      }
      if ($name === 'terminal') {
        $task->resolve()->save();
      }
      $tasks[$name] = $task;
    }
    $triggers = $this->container->get('plugin.manager.task_job.trigger');
    $contexts = ['original' => $original, 'replacement' => $replacement];
    $this->assertSame([], $triggers->handleTrigger('entity.replaced:entity_test', $contexts, FALSE));
    $this->assertSame($original->uuid(), $this->fresh($tasks['move'])->event_dependencies->entity->bindings->first()->entity_uuid);
    try {
      $triggers->handleTrigger('entity.replaced:entity_test', ['original' => $original]);
      $this->fail('A replacement must not reuse the preceding event context.');
    }
    catch (ContextException $exception) {
      $this->assertNotEmpty($exception->getMessage());
    }
    $account = $this->container->get('current_user')->getAccount();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    try {
      $triggers->handleTrigger('entity.replaced:entity_test', $contexts);
      $this->fail('A replacement must respect task update access.');
    }
    catch (AccessDeniedHttpException $exception) {
      $this->assertNotEmpty($exception->getMessage());
    }
    finally {
      $this->container->get('current_user')->setAccount($account);
    }
    $this->assertSame($original->uuid(), $this->fresh($tasks['move'])->event_dependencies->entity->bindings->first()->entity_uuid);
    $this->assertContains('task_dependency_job', $job->getDependencies()['module']);
    $database = $this->container->get('database');
    $before = $database->select('task_dependency_history')->countQuery()->execute()->fetchField();
    $transaction = $database->startTransaction();
    $triggers->handleTrigger('entity.replaced:entity_test', $contexts);
    $transaction->rollBack();
    unset($transaction);
    $this->container->get('entity_type.manager')->getStorage('task_dependency')->resetCache();
    $this->assertSame($before, $database->select('task_dependency_history')->countQuery()->execute()->fetchField());
    $this->assertSame($original->uuid(), $this->fresh($tasks['move'])->event_dependencies->entity->bindings->first()->entity_uuid);
    $event = new EntityReplacementEvent($original, $replacement);
    $this->container->get('event_dispatcher')->dispatch($event);
    foreach ($tasks as $name => $task) {
      $expected = $name === 'move' ? $replacement : $original;
      $this->assertSame($expected->uuid(), $this->fresh($task)->event_dependencies->entity->bindings->first()->entity_uuid, $name);
    }
    $after = $database->select('task_dependency_history')->countQuery()->execute()->fetchField();
    $this->assertSame((int) $before + 1, (int) $after);
    $this->container->get('event_dispatcher')->dispatch($event);
    $this->assertSame($after, $database->select('task_dependency_history')->countQuery()->execute()->fetchField());
    $original->set('name', 'approved')->save();
    $this->drain();
    $this->assertSame('waiting', $this->fresh($tasks['move'])->status->value);
    $replacement->set('name', 'approved')->save();
    $this->drain();
    $this->assertSame('active', $this->fresh($tasks['move'])->status->value);
    $this->assertCount(5, $this->container->get('entity_type.manager')->getStorage('task')->loadMultiple());
  }

  /**
   * Flexiform API input retains working data until the ordinary save step.
   */
  public function testFlexiformApi(): void {
    $source = Task::create(['title' => 'Prerequisite', 'description' => 'Source']);
    $source->save();
    $task = Task::create(['title' => 'Work', 'description' => 'Work']);
    $task->save();
    $form = $this->container->get('flexiform.form_factory')->create([
      'data' => [
        'task' => [
          'plugin' => 'provided',
          'entity_type' => 'task',
          'bundle' => 'task',
          'save_on_submit' => TRUE,
        ],
      ],
      'components' => ['dependencies' => ['component_type' => 'task_dependencies', 'context' => 'task']],
    ]);
    $store = $this->container->get('flexiform.instance_store');
    $uuid = $this->container->get('uuid');
    $id = $uuid->generate();
    $store->start($id, $form, ['task' => $task], ['host' => 'test']);
    $view = $store->getRepresentation($id);
    $this->assertSame('ready', $view['status'], json_encode($view));
    $this->assertTrue($view['supported']);
    $this->assertSame('array', $view['schema']['properties']->dependencies['type']);
    $store->act($id, 1, $uuid->generate(), 'update', [
      'dependencies' => [[
        'trigger' => 'task.resolved',
        'action' => 'activate',
        'entity_id' => (string) $source->id(),
      ],
      ],
    ]);
    $this->assertTrue($this->fresh($task)->event_dependencies->isEmpty());
    $view = $store->getRepresentation($id);
    $this->assertCount(1, $view['data']->dependencies);
    $this->assertSame((string) $source->id(), $view['data']->dependencies[0]['entity_id']);
    $result = $store->act($id, 2, $uuid->generate(), 'submit', []);
    $this->assertSame('complete', $result['status']);
    $this->assertSame('waiting', $this->fresh($task)->status->value);
  }

  /**
   * The field widget and Flexiform use the same unsaved dependency records.
   */
  public function testHtmlWidget(): void {
    $source = Task::create(['title' => 'Prerequisite']);
    $source->save();
    $task = Task::create(['title' => 'Work']);
    $widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $task->get('event_dependencies')->getFieldDefinition(),
      'configuration' => ['type' => 'task_dependencies', 'settings' => []],
    ]);
    $state = new FormState();
    $form = ['#parents' => []];
    $widget->form($task->event_dependencies, $form, $state);
    $state->setValues([
      'event_dependencies' => [[
        'id' => '',
        'trigger' => 'task.resolved',
        'action' => 'activate',
        'entity_id' => (string) $source->id(),
        'field' => 'status',
        'property' => 'value',
        'value' => '',
      ],
      ],
    ]);
    $widget->extractFormValues($task->event_dependencies, $form, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertTrue($task->event_dependencies->entity->isNew());
    $task->save();
    $this->assertSame('waiting', $task->status->value);
  }

  /**
   * Shared task-resolution matching also creates new work through job triggers.
   */
  public function testSharedJobEvent(): void {
    $job = Job::create([
      'id' => 'follow_up',
      'label' => 'Follow up',
      'triggers' => [
        'resolved' => [
          'id' => 'dependency_event:task.resolved',
          'key' => 'resolved',
          'template' => ['id' => 'default', 'uuid' => 'resolved', 'label' => 'Follow up', 'components' => []],
        ],
      ],
    ]);
    $job->save();
    $source = Task::create(['title' => 'Prerequisite']);
    $source->save();
    $source->resolve()->save();
    $source->save();
    $tasks = $this->container->get('entity_type.manager')->getStorage('task')->loadByProperties(['job' => $job->id()]);
    $this->assertCount(1, $tasks);
  }

  /**
   * Dependency matching activates automatic work and the task resolves itself.
   */
  public function testAutomaticCompletionChain(): void {
    $source = Task::create(['title' => 'Prerequisite']);
    $source->save();
    $job = Job::create([
      'id' => 'automatic',
      'label' => 'Automatic work',
      'default_checklist' => [
        'run' => [
          'label' => 'Run',
          'handler' => 'single_step_test',
          'handler_configuration' => ['context_mapping' => ['value' => 'checklist:entity.title.value']],
        ],
      ],
    ]);
    $job->save();
    $task = Task::create(['title' => 'Follow up', 'job' => $job]);
    $task->event_dependencies[] = ['entity' => $this->container->get('task_dependency.manager')->create($task, 'task.resolved', [], 'activate', $source)];
    $task->save();
    $source->resolve()->save();
    $this->drain();
    $this->container->get('task_checklist.scheduler')->dispatch();
    $queue = $this->container->get('queue')->get('task_checklist_process');
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('task_checklist_process');
    while ($message = $queue->claimItem()) {
      $worker->processItem($message->data);
      $queue->deleteItem($message);
    }
    $saved = $this->fresh($task);
    $this->assertSame('resolved', $saved->status->value);
    $this->assertSame('complete', $saved->resolution->value);
  }

  /**
   * Trigger context definitions determine editor types and job contexts.
   */
  public function testTriggerContextsDriveEditors(): void {
    $events = $this->container->get('plugin.manager.task_dependency.trigger');
    $jobs = $this->container->get('plugin.manager.task_job.trigger');
    $task_definition = $events->getDefinition('task.resolved')['context_definitions']['task'];
    $this->assertSame('entity:task', $task_definition->getDataType());
    $this->assertEquals($task_definition, $jobs->getDefinition('dependency_event:task.resolved')['context_definitions']['task']);
    $user_definition = $events->getDefinition('entity.state:user')['context_definitions']['entity'];
    $this->assertEquals($user_definition, $jobs->getDefinition('dependency_event:entity.state:user')['context_definitions']['entity']);
    $task = Task::create(['title' => 'Work']);
    $widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $task->get('event_dependencies')->getFieldDefinition(),
      'configuration' => ['type' => 'task_dependencies', 'settings' => []],
    ]);
    $form = ['#parents' => []];
    $state = new FormState();
    $element = $widget->formElement($task->event_dependencies, 0, [], $form, $state);
    $this->assertArrayNotHasKey('entity_type', $element);
    $this->assertArrayNotHasKey('follow_replacement', $element);
    $this->assertSame('task', $element['entity_id']['#target_type']);
    $this->assertEquals($task_definition->getLabel(), $element['entity_id']['#title']);
    $state->setUserInput(['event_dependencies' => [['trigger' => 'entity.state:user']]]);
    $element = $widget->formElement($task->event_dependencies, 0, [], $form, $state);
    $this->assertSame('user', $element['entity_id']['#target_type']);
    $this->assertEquals($user_definition->getLabel(), $element['entity_id']['#title']);
    $component = $this->container->get('plugin.manager.entity_template.component')->createInstance('task_dependency', ['trigger' => 'entity.state:user']);
    $this->assertEquals(['entity' => $user_definition], $component->getContextDefinitions());
    $config_form = $component->buildConfigurationForm([], new FormState());
    $this->assertArrayNotHasKey('entity_type', $config_form);
    $this->assertArrayNotHasKey('follow_replacement', $config_form);
    $this->assertArrayHasKey('entity', $config_form['context_mapping']);
  }

}
