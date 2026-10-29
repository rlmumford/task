<?php

namespace Drupal\Tests\task_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\service\Entity\Service;
use Drupal\service\Entity\ServiceType;
use Drupal\task\Entity\Task;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\views\Views;

/**
 * Filters service work without any application-specific case module.
 *
 * @group task_ui
 */
class ServiceFilterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity', 'views',
    'task', 'task_ui', 'task_checklist', 'checklist', 'plugin_reference', 'service',
    'typed_data', 'typed_data_plus', 'typed_data_reference',
  ];

  /**
   * Services and tasks each retain their independent access boundaries.
   */
  public function testServiceFilter(): void {
    $this->installSchema('task_checklist', ['task_checklist_request']);
    $this->installSchema('service', ['service_hierarchy_lock']);
    $this->container->get('database')->insert('service_hierarchy_lock')->fields(['id' => 1])->execute();
    foreach (['user', 'service', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user', 'task_ui']);
    $this->container->get('router.builder')->rebuild();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    Role::create([
      'id' => 'worker',
      'label' => 'Worker',
      'permissions' => ['view assigned tasks', 'view managed service'],
    ])->save();
    $worker = User::create(['name' => 'Worker', 'status' => 1, 'roles' => ['worker']]);
    $worker->save();
    $this->container->get('current_user')->setAccount($worker);
    $services = [];
    foreach (['alpha', 'beta', 'hidden'] as $name) {
      ServiceType::create(['id' => $name, 'label' => $name])->save();
      $services[$name] = Service::create([
        'type' => $name,
        'label' => $name,
        'manager' => $name === 'hidden' ? 1 : $worker->id(),
      ]);
      $services[$name]->save();
    }
    for ($i = 0; $i < 26; $i++) {
      Task::create(['title' => 'Alpha work ' . $i, 'service' => $services['alpha'], 'assignee' => $worker])->save();
    }
    Task::create(['title' => 'Beta work', 'service' => $services['beta'], 'assignee' => $worker])->save();
    Task::create(['title' => 'Hidden task', 'service' => $services['alpha'], 'assignee' => 1])->save();
    Task::create([
      'title' => 'Readable task with hidden service',
      'service' => $services['hidden'],
      'assignee' => $worker,
    ])->save();
    Task::create(['title' => 'No service', 'assignee' => $worker])->save();

    $selection = $this->container->get('plugin.manager.entity_reference_selection')->getInstance([
      'target_type' => 'service',
      'handler' => 'task_ui_service',
    ]);
    $options = $selection->getReferenceableEntities(NULL, 'CONTAINS', 10);
    $this->assertSame('alpha', $options['alpha'][$services['alpha']->id()]);
    $this->assertSame('beta', $options['beta'][$services['beta']->id()]);
    $this->assertEmpty($options['hidden'] ?? []);

    foreach ([
      ['alpha (' . $services['alpha']->id() . ')', 26],
      ['beta (' . $services['beta']->id() . ')', 1],
      ['hidden (' . $services['hidden']->id() . ')', 0],
      ['Missing (9999999)', 0],
      ['', 29],
    ] as [$input, $count]) {
      $view = Views::getView('task_workspace');
      $view->setDisplay('team');
      $view->addHandler('team', 'filter', 'task', 'task_ui_service', [
        'operator' => '=',
        'value' => '',
        'exposed' => TRUE,
        'expose' => ['identifier' => 'service', 'label' => 'Service', 'required' => FALSE],
      ]);
      $view->setExposedInput(['service' => $input]);
      $view->execute();
      $this->assertContains('service', $view->filter['task_ui_service']->calculateDependencies()['module']);
      $this->assertEquals($count, $view->total_rows);
      $this->assertCount(min(25, $count), $view->result);
    }
  }

}
