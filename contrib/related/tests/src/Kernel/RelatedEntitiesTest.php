<?php

namespace Drupal\Tests\task_related\Kernel;

use Drupal\checklist\Entity\ChecklistItem;
use Drupal\Core\Entity\EntityInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\views\Views;

/**
 * Proves context/outcome navigation is independent of document storage.
 *
 * @group task
 */
class RelatedEntitiesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'datetime', 'entity',
    'entity_test', 'views', 'task', 'task_ui', 'task_related', 'task_related_test',
    'typed_reference_index', 'task_context', 'task_checklist', 'checklist',
    'plugin_reference', 'typed_data', 'typed_data_plus', 'typed_data_reference',
    'typed_data_context_assignment',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'entity_test', 'entity_test_rev', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('task_checklist', ['task_checklist_request']);
    $this->installSchema('typed_reference_index', ['typed_reference_index']);
    $this->installSchema('checklist', ['checklist_attempt', 'checklist_attempt_head', 'checklist_attempt_event']);
    $this->installConfig(['system', 'user', 'task_ui', 'task_related']);
    User::create(['uid' => 1, 'name' => 'root'])->save();
    Role::create([
      'id' => 'worker',
      'label' => 'Worker',
      'permissions' => ['view assigned tasks', 'view test entity', 'administer permissions'],
    ])->save();
    $user = User::create(['name' => 'Worker', 'roles' => ['worker']]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Entity types disambiguate IDs and labels survive both directions.
   */
  public function testGenericRelationships(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('document'));
    $record = EntityTest::create(['name' => 'Source record']);
    $record->save();
    $other = EntityTestRev::create(['name' => 'Reviewed version']);
    $other->save();
    $this->assertEquals($record->id(), $other->id(), 'The fixture IDs intentionally collide.');
    $role = Role::create(['id' => 'review_result', 'label' => 'Review result']);
    $role->save();
    $task = Task::create([
      'title' => 'Process records',
      'assignee' => $this->container->get('current_user')->id(),
      'subject' => $record->id(),
      'context' => [['key' => 'role', 'value' => $role->id()]],
    ]);
    $task->save();
    $item = ChecklistItem::create([
      'checklist_type' => 'related_work_test',
      'name' => 'prepare',
      'title' => 'Prepare records',
      'handler' => ['id' => 'simply_checkable'],
      'checklist' => ['target_id' => $task->id(), 'checklist_key' => 'checklist'],
      'outcomes' => [
        ['key' => 'record', 'value' => $record->id()],
        ['key' => 'linked', 'value' => $record->id()],
        ['key' => 'records[0]', 'value' => $record->id()],
        ['key' => 'revisioned', 'value' => $other->id()],
      ],
    ]);
    $item->save();
    foreach ([$record, $other, $role] as $target) {
      $this->assertEquals(1, $this->view($target)->total_rows);
    }
    $extra = $this->container->get('entity_field.manager')->getExtraFields('entity_test', 'entity_test');
    $this->assertArrayHasKey('related_tasks', $extra['display']);
    $build = [];
    $display = $this->container->get('entity_display.repository')->getViewDisplay('entity_test', 'entity_test');
    $display->setComponent('related_tasks');
    $this->container->get('module_handler')->invoke('task_related', 'entity_view', [
      &$build, $record, $display, 'default',
    ]);
    $this->assertSame(
      ['entity_test:' . $record->id()],
      $build['related_tasks']['tasks']['#arguments'],
    );
    $presentation = $this->container->get('task_related.presentation');
    $build = $presentation->records($task);
    $this->assertCount(3, $build['groups']);
    $this->assertArrayNotHasKey('user', $build['groups'], 'The assignee is not a task context.');
    $labels = $presentation->relations($task, $record);
    $text = implode(' ', array_map(static fn($item) => (string) $item['#markup'], $labels['#items']));
    $this->assertStringContainsString('Created record (record)', $text);
    $this->assertStringContainsString('Linked record (linked)', $text);
    $this->assertStringContainsString('Created records (records[0])', $text);
    $this->assertStringContainsString('Prepare records (prepare)', $text);
    $this->assertStringNotContainsString('Reviewed record', $text);
    $task->set('subject', NULL)->save();
    $item->set('outcomes', [['key' => 'revisioned', 'value' => $other->id()]])->save();
    $this->assertEquals(0, $this->view($record)->total_rows);
    $this->assertEquals(1, $this->view($other)->total_rows);
    $other->set('name', 'Hidden record')->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('entity_test_rev')->resetCache();
    $this->assertEquals(0, $this->view($other)->total_rows);
    $this->assertArrayNotHasKey('entity_test_rev', $presentation->records($task)['groups']);
    $role->delete();
    $this->assertSame([], $presentation->records($task));
    $work = $this->container->get('task_related.work');
    $this->assertNull($work->target('unknown_type:123'));
    $this->assertNull($work->target('user_role:'));
  }

  /**
   * Runs the generic View, including entity access and task query access.
   */
  private function view(EntityInterface $target) {
    $view = Views::getView('task_related_tasks');
    $view->setDisplay('embed');
    $view->setArguments([$target->getEntityTypeId() . ':' . $target->id()]);
    $view->execute();
    return $view;
  }

}
