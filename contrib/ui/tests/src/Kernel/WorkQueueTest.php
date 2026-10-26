<?php

namespace Drupal\Tests\task_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\views\Views;

/**
 * Exercises query access, pagination and shared readiness in the workspace.
 *
 * @group task_ui
 */
class WorkQueueTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity', 'views',
    'task', 'task_ui', 'task_checklist', 'checklist', 'plugin_reference',
    'typed_data', 'typed_data_plus', 'typed_data_reference',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('task_checklist', ['task_checklist_request']);
    foreach (['user', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user', 'task_ui']);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Only readable tasks contribute rows or pager totals.
   */
  public function testAccessPaginationAndReadiness(): void {
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    Role::create(['id' => 'worker', 'label' => 'Worker', 'permissions' => ['view assigned tasks']])->save();
    $user = User::create(['name' => 'worker', 'roles' => ['worker'], 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    Task::create(['title' => 'Inaccessible', 'assignee' => 1])->save();
    for ($i = 0; $i < 26; $i++) {
      Task::create(['title' => 'Review ' . $i, 'assignee' => $user->id()])->save();
    }
    $view = Views::getView('task_workspace');
    $view->setDisplay('team');
    $view->execute();
    $this->assertCount(25, $view->result);
    $this->assertEquals(26, $view->total_rows);
    $unassigned = Views::getView('task_workspace');
    $unassigned->setDisplay('unassigned');
    $unassigned->execute();
    $this->assertCount(0, $unassigned->result);
    $future = Task::create([
      'title' => 'Scheduled review',
      'assignee' => $user->id(),
      'start' => gmdate('Y-m-d\TH:i:s', time() + 3600),
    ]);
    $future->save();
    $mine = Views::getView('task_workspace');
    $mine->setDisplay('mine');
    $mine->setExposedInput(['title' => 'Scheduled']);
    $mine->execute();
    $this->assertCount(1, $mine->result);
    $this->assertEquals($future->id(), $mine->result[0]->_entity->id());
    $this->assertSame('Scheduled', (string) $this->container->get('task_ui.presenter')->state($future));
    $future->resolve()->save();
    foreach (['mine' => 0, 'history' => 1] as $display => $count) {
      $view = Views::getView('task_workspace');
      $view->setDisplay($display);
      $view->setExposedInput(['title' => 'Scheduled']);
      $view->execute();
      $this->assertCount($count, $view->result);
    }
  }

}
