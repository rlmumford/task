<?php

namespace Drupal\Tests\task_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\views\Views;

/**
 * Filters saved job identities without a job catalogue or definition access.
 *
 * @group task_ui
 */
class JobFilterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity',
    'views', 'task', 'task_ui', 'task_context', 'task_checklist', 'checklist',
    'task_job', 'entity_template', 'typed_data', 'typed_data_plus',
    'plugin_reference', 'typed_data_reference', 'typed_data_context_assignment',
    'inline_entity_form',
  ];

  /**
   * All revisions match one identity; query access still controls pager totals.
   */
  public function testJobFilter(): void {
    $this->installSchema('task_checklist', ['task_checklist_request']);
    $this->installSchema('task_job', ['task_job_trigger_index']);
    foreach (['user', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user', 'task_ui']);
    $this->container->get('router.builder')->rebuild();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    Role::create(['id' => 'worker', 'label' => 'Worker', 'permissions' => ['view assigned tasks']])->save();
    $user = User::create(['name' => 'worker', 'roles' => ['worker'], 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $jobs = [];
    foreach (['review', 'other', 'secret', 'unused'] as $id) {
      $jobs[$id] = Job::create(['id' => $id, 'label' => ucfirst($id) . ' job']);
      $jobs[$id]->save();
    }
    $jobs['other']->set('label', 'Review job')->save();
    $versions = $this->container->get('task_job.version_resolver');
    foreach (['1', '2'] as $version) {
      $job = $versions->createVersion($jobs['review'], $version);
      $job->save();
      for ($i = 0; $i < 13; $i++) {
        Task::create(['title' => 'Review ' . $version . '-' . $i, 'job' => $job, 'assignee' => $user])->save();
      }
    }
    Task::create(['title' => 'Other work', 'job' => $jobs['other'], 'assignee' => $user])->save();
    Task::create(['title' => 'Private review', 'job' => $jobs['review'], 'assignee' => 1])->save();
    Task::create(['title' => 'Secret work', 'job' => $jobs['secret'], 'assignee' => 1])->save();
    Task::create(['title' => 'Manual work', 'assignee' => $user])->save();
    $this->assertFalse($jobs['review']->access('view'));
    foreach ([
      ['task_job:review', 26],
      ['task_job:other', 1],
      ['task_job:secret', 0],
      ['task_job:unused', 0],
      ['organization_job:review', 0],
      ['missing', 0],
      ['All', 28],
    ] as [$input, $count]) {
      $view = Views::getView('task_workspace');
      $view->setDisplay('team');
      $view->addHandler('team', 'filter', 'task', 'task_ui_job', [
        'operator' => 'in',
        'value' => [],
        'exposed' => TRUE,
        'expose' => ['identifier' => 'job', 'label' => 'Job', 'required' => FALSE],
      ]);
      $view->setExposedInput(['job' => $input]);
      $view->execute();
      $this->assertEquals($count, $view->total_rows, $input);
      $this->assertCount(min(25, $count), $view->result);
      $options = $view->filter['task_ui_job']->getValueOptions();
      $this->assertStringContainsString('Review job', $options['task_job:review']);
      $this->assertNotSame($options['task_job:review'], $options['task_job:other']);
      $this->assertNotContains('Secret job', $options);
      $this->assertNotContains('Unused job', $options);
      $this->assertContains('task_job', $view->filter['task_ui_job']->calculateDependencies()['module']);
    }
  }

}
