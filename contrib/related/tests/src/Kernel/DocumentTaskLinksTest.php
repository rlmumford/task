<?php

namespace Drupal\Tests\task_related\Kernel;

use Drupal\checklist\Entity\ChecklistItem;
use Drupal\document\Entity\Document;
use Drupal\document\Entity\DocumentType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\views\Views;

/**
 * Tests existing document relationships through access-aware Views.
 *
 * @group document
 */
class DocumentTaskLinksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'text', 'filter', 'options', 'datetime',
    'entity', 'views', 'document', 'task_related', 'task_related_document_test',
    'document_checklist', 'typed_reference_index', 'task', 'task_ui',
    'task_context', 'task_checklist', 'checklist', 'plugin_reference',
    'typed_data', 'typed_data_plus', 'typed_data_reference',
    'typed_data_context_assignment',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'document', 'document_review', 'task', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('task_checklist', ['task_checklist_request']);
    $this->installSchema('typed_reference_index', ['typed_reference_index']);
    $this->installSchema('checklist', ['checklist_attempt', 'checklist_attempt_head', 'checklist_attempt_event']);
    $this->installConfig(['system', 'user', 'task_ui', 'task_related']);
    User::create(['uid' => 1, 'name' => 'root'])->save();
    Role::create(['id' => 'worker', 'label' => 'Worker', 'permissions' => ['view assigned tasks', 'view any document']])->save();
    $user = User::create(['name' => 'Worker', 'roles' => ['worker']]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    DocumentType::create(['id' => 'filing', 'label' => 'Filing'])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Creates a saved document.
   */
  protected function document(string $label = 'Filing'): Document {
    $document = Document::create(['type' => 'filing', 'label' => $label]);
    $document->save();
    return $document;
  }

  /**
   * Contexts and outcomes reuse the same existing document and task host.
   */
  public function testContextsAndOutcomes(): void {
    $document = $this->document();
    $other = $this->document('Other');
    $task = Task::create([
      'title' => 'Review filing',
      'assignee' => $this->container->get('current_user')->id(),
      'input_document' => $document->id(),
      'context' => [
        ['key' => 'documents[0]', 'value' => $document->id()],
        ['key' => 'packet', 'blob' => ['documents' => [$document->id(), $other->id()], 'number' => $other->id()]],
      ],
    ]);
    $task->save();
    $item = ChecklistItem::create([
      'checklist_type' => 'document_work_test',
      'name' => 'review',
      'title' => 'Review <existing> & confirm',
      'handler' => ['id' => 'document_review'],
      'checklist' => ['target_id' => $task->id(), 'checklist_key' => 'checklist'],
      'outcomes' => [['key' => 'document', 'value' => $document->id()]],
    ]);
    $item->save();
    $this->assertEquals(1, $this->view($document)->total_rows);
    $task->resolve()->save();
    // Completing the task does not remove its contexts or outcomes.
    $this->assertEquals(1, $this->view($document)->total_rows);
    $work = $this->container->get('task_related.work');
    $references = $work->references($task);
    $this->assertCount(5, $references);
    $outcome = array_values(array_filter($references, fn($reference) => $reference['kind'] === 'outcome'))[0];
    $this->assertSame('review', $outcome['item_name']);
    $this->assertSame('document', $outcome['name']);
    $this->assertSame('Reviewed document', $outcome['label']);
    $this->assertEquals($document->id(), $outcome['entity_id']);
    $labels = $this->container->get('task_related.presentation')->relations($task, $document);
    $html = '';
    foreach ($labels['#items'] as $label) {
      $html .= (string) $this->container->get('renderer')->renderInIsolation($label);
    }
    $this->assertStringContainsString('Review &lt;existing&gt; &amp; confirm', $html);
    $this->assertStringNotContainsString('<existing>', $html);
    $task->set('input_document', NULL)->set('context', [])->save();
    $this->assertEquals(1, $this->view($document)->total_rows, 'The outcome alone links the task.');
    $item->set('outcomes', [['key' => 'document', 'value' => $other->id()]])->save();
    $this->assertEquals(0, $this->view($document)->total_rows, 'No independent former-link history remains.');
    $this->assertEquals(1, $this->view($other)->total_rows);
    // Moving the item uses its actual host; no task ID is cached in the index.
    $second = Task::create(['title' => 'Other task', 'assignee' => $this->container->get('current_user')->id()]);
    $second->save();
    $item->get('checklist')->target_id = $second->id();
    $item->save();
    $this->assertEquals($second->id(), $this->view($other)->result[0]->_entity->id());
    $item->delete();
    $this->assertEquals(0, $this->view($other)->total_rows);
  }

  /**
   * Both access filters and the pager operate on distinct tasks.
   */
  public function testPaginationAndAccess(): void {
    $document = $this->document();
    for ($i = 0; $i < 27; $i++) {
      Task::create([
        'title' => 'Review ' . $i,
        'assignee' => $i === 26 ? 1 : $this->container->get('current_user')->id(),
        'input_document' => $document->id(),
        'context' => [['key' => 'document', 'value' => $document->id()]],
      ])->save();
    }
    $view = $this->view($document);
    $this->assertEquals(26, $view->total_rows);
    $this->assertCount(25, $view->result);
    $this->assertCount(1, $this->view($document, 1)->result);
    // The direct reference works even without the typed-reference cache.
    $this->container->get('typed_reference_index.index')->clear();
    $this->assertEquals(26, $this->view($document)->total_rows);
    $role = Role::load('worker');
    $role->revokePermission('view any document')->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('document')->resetCache();
    $this->assertCount(0, $this->view($document)->result);
  }

  /**
   * Non-default task revisions cannot replace current reference query results.
   */
  public function testNonDefaultTaskRevision(): void {
    $document = $this->document();
    $other = $this->document('Draft input');
    $task = Task::create([
      'title' => 'Review',
      'input_document' => $document->id(),
      'assignee' => $this->container->get('current_user')->id(),
    ]);
    $task->save();
    $task->setNewRevision(TRUE);
    $task->isDefaultRevision(FALSE);
    $task->set('input_document', $other->id())->save();
    $this->assertEquals(1, $this->view($document)->total_rows);
    $this->assertEquals(0, $this->view($other)->total_rows);
    // Nested contexts saved on the draft must not replace current links either.
    $task->set('context', [['key' => 'document', 'value' => $other->id()]])->save();
    $this->assertEquals(1, $this->view($document)->total_rows);
    $this->assertEquals(0, $this->view($other)->total_rows);
  }

  /**
   * Executes the actual document View, including query access and pager.
   */
  protected function view(Document $document, int $page = 0) {
    $view = Views::getView('task_related_tasks');
    $view->setDisplay('embed');
    $view->setArguments(['document:' . $document->id()]);
    $view->setCurrentPage($page);
    $view->execute();
    return $view;
  }

}
