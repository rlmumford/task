<?php

namespace Drupal\Tests\task_job\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;

/**
 * Covers atomic job references and upgrades from configuration references.
 */
class JobReferenceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime',
    'entity', 'task', 'task_context', 'task_checklist', 'checklist',
    'task_job', 'entity_template', 'typed_data', 'typed_data_plus', 'views',
    'plugin_reference', 'typed_data_reference', 'typed_data_context_assignment',
    'inline_entity_form', 'checklist_state_test',
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
   * Only one complete, supported job identity can be saved.
   */
  public function testInvalidReferencesFailClosed(): void {
    $job = Job::create(['id' => 'review', 'label' => 'Review']);
    $job->save();
    foreach ([
      ['target_type' => 'task_job'],
      ['version' => '6'],
      ['target_type' => 'user', 'target_id' => '1'],
      ['target_type' => 'task_job', 'target_id' => 'missing'],
      [
        ['target_type' => 'task_job', 'target_id' => 'review'],
        ['target_type' => 'task_job', 'target_id' => 'review'],
      ],
    ] as $reference) {
      try {
        Task::create(['title' => 'Invalid work', 'job' => $reference])->save();
        $this->fail('An invalid or ambiguous job reference must not be saved.');
      }
      catch (EntityStorageException | \DomainException $e) {
        $this->assertNotEmpty($e->getMessage());
      }
    }
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('task')->getQuery()->accessCheck(FALSE)->count()->execute());
  }

  /**
   * Scalar callers retain config jobs, and previews retain unsaved definitions.
   */
  public function testScalarReferencesAndDrafts(): void {
    $job = Job::create(['id' => 'review', 'label' => 'Review']);
    $job->save();
    $task = Task::create(['title' => 'Work', 'job' => 'review']);
    $task->save();
    $task = Task::load($task->id());
    $this->assertSame('task_job', $task->get('job')->target_type);
    $this->assertSame('review', $task->get('job')->entity->id());
    $job->set('label', 'Unsaved draft');
    $preview = Task::create(['job' => $job]);
    $this->assertSame('Unsaved draft', $preview->get('job')->entity->label());
    $preview->get('job')->target_id = 'review';
    $this->assertSame('Review', $preview->get('job')->entity->label());
  }

  /**
   * Existing Views field names render the job using the new formatter.
   */
  public function testTaskBoardJobField(): void {
    $user = User::create(['name' => 'root', 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $job = Job::create(['id' => 'review', 'label' => 'Review filing']);
    $job->save();
    Task::create(['title' => 'Work', 'job' => $job])->save();
    $view = View::create([
      'id' => 'reference_board',
      'base_table' => 'task',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'fields' => [
              'job' => [
                'id' => 'job',
                'table' => 'task',
                'field' => 'job',
                'plugin_id' => 'field',
                'type' => 'entity_reference_label',
              ],
            ],
          ],
        ],
      ],
    ])->getExecutable();
    $view->execute();
    $this->assertCount(1, $view->result);
    $build = $view->render();
    $this->assertStringContainsString('Review filing', (string) $this->container->get('renderer')->renderRoot($build));
  }

  /**
   * Existing data survives the schema change and a repeated upgrade.
   */
  public function testUpgradePreservesNamedVersions(): void {
    $job = Job::create(['id' => 'review', 'label' => 'Review']);
    $job->save();
    $version = $this->container->get('task_job.version_resolver')->createVersion($job, '6');
    $version->set('label', 'Version six')->save();
    $task = Task::create(['title' => 'Existing work']);
    $task->save();
    $legacy = Task::create(['title' => 'Legacy version ID']);
    $legacy->save();
    $empty = Task::create(['title' => 'Ad hoc work']);
    $empty->save();
    $manager = $this->container->get('entity.definition_update_manager');
    $manager->uninstallFieldStorageDefinition($manager->getFieldStorageDefinition('job', 'task'));
    $manager->installFieldStorageDefinition('job', 'task', 'task_job', BaseFieldDefinition::create('entity_reference')->setSetting('target_type', 'task_job'));
    $manager->installFieldStorageDefinition('job_version', 'task', 'task_job', BaseFieldDefinition::create('string')->setSetting('max_length', 128));
    $this->container->get('database')->update('task')->fields(['job' => 'review', 'job_version' => '6'])->condition('id', $task->id())->execute();
    $this->container->get('database')->update('task')->fields(['job' => 'review--v6'])->condition('id', $legacy->id())->execute();
    $this->container->get('module_handler')->loadInclude('task_job', 'install');
    task_job_update_8007();
    task_job_update_8007();
    $storage = $this->container->get('entity_type.manager')->getStorage('task');
    $loaded = $storage->loadUnchanged($task->id());
    $this->assertSame('Version six', $loaded->get('job')->entity->label());
    $this->assertSame('6', $loaded->get('job')->version);
    $legacy = $storage->loadUnchanged($legacy->id());
    $this->assertSame('review', $legacy->get('job')->target_id);
    $this->assertSame('6', $legacy->get('job')->version);
    $this->assertCount(2, $storage->loadByProperties($job->getTaskQueryConditions()));
    $this->assertTrue($storage->loadUnchanged($empty->id())->get('job')->isEmpty());
    $loaded->set('title', 'Still editable')->save();
    $this->assertFalse($this->container->get('database')->schema()->fieldExists('task', 'job'));
    $this->assertNull($manager->getFieldStorageDefinition('job_version', 'task'));
    $this->assertArrayNotHasKey('task', $manager->getChangeList());
  }

}
