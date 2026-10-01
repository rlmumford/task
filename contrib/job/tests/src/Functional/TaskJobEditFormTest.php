<?php

namespace Drupal\Tests\task_job\Functional;

use Drupal\task_job\Entity\Job;
use Drupal\task_job\JobVersionId;
use Drupal\Tests\BrowserTestBase;

/**
 * Exercises one working draft across tabs, nested forms and explicit commit.
 *
 * @group task_job
 */
class TaskJobEditFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'task_job', 'task_dependency_job'];

  /**
   * Creates a job with an editable trigger and logs in its administrator.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalLogin($this->drupalCreateUser(['administer task jobs']));
    Job::create([
      'id' => 'follow_up',
      'label' => 'Follow up',
      'triggers' => [
        'replacement' => [
          'id' => 'entity.replaced:task',
          'key' => 'replacement',
          'template' => ['id' => 'default', 'uuid' => 'replacement', 'components' => []],
          'action' => [
            'plugin' => 'retarget_dependencies',
            'configuration' => [
              'dependency_trigger' => 'task.resolved',
              'dependency_action' => 'activate',
              'context_mapping' => ['original' => 'original', 'replacement' => 'replacement'],
            ],
          ],
        ],
      ],
    ])->save();
  }

  /**
   * Loads persisted configuration, bypassing the kernel's entity cache.
   */
  protected function saved(string $id = 'follow_up'): ?Job {
    return $this->container->get('entity_type.manager')->getStorage('task_job')->loadUnchanged($id);
  }

  /**
   * Without JavaScript, apply edits explicitly before following a local task.
   */
  protected function switchTab(array $values, string $label): void {
    $this->submitForm($values, 'Apply to draft');
    $this->clickLink($label);
    $this->assertSession()->statusCodeEquals(200);
    $section = [
      'Checklist' => '',
      'Triggers' => '/triggers',
      'Contexts' => '/contexts',
      'Assignment rules' => '/assignment',
      'Settings' => '/settings',
    ][$label];
    $this->assertStringEndsWith('/edit' . $section, parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH));
    $this->assertSession()->linkExists($label);
  }

  /**
   * Tab changes and child forms retain edits; Save is the only config write.
   */
  public function testDraftAcrossTabs(): void {
    $this->drupalGet('/admin/config/task/job/follow_up/edit/settings');
    $this->switchTab(['label' => 'Draft label'], 'Triggers');
    $this->assertSame('Follow up', $this->saved()->label());
    $this->assertSession()->fieldNotExists('label');
    $this->switchTab([
      'triggers[replacement][action][configuration][dependency_action]' => 'invalidate',
    ], 'Contexts');
    $this->submitForm([
      'context[_add_new][label]' => 'Document',
      'context[_add_new][key]' => 'document',
      'context[_add_new][type]' => 'entity:task',
    ], 'Add');
    $this->switchTab([], 'Assignment rules');
    $this->switchTab(['assignment' => 'creator'], 'Checklist');
    $this->clickLink('Add Checklist Item');
    $this->clickLink('Simple Checkbox');
    $this->submitForm(['name' => 'review', 'label' => 'Review replacement work'], 'Add');
    $this->assertSession()->pageTextContains('You have unsaved changes.');
    $this->assertSession()->pageTextContains('Review replacement work');
    $this->assertSame([], $this->saved()->getChecklistItems());
    $this->assertSame([], $this->saved()->getContextDefinitions());
    $this->switchTab([], 'Settings');
    $this->assertSession()->fieldValueEquals('label', 'Draft label');
    $this->submitForm([], 'Save');
    $this->assertSession()->pageTextContains('The job has been saved.');
    $job = $this->saved();
    $this->assertSame('Draft label', $job->label());
    $this->assertSame('creator', $job->get('assignment'));
    $this->assertSame('entity:task', $job->getContextDefinition('document')->getDataType());
    $this->assertSame('Review replacement work', $job->getChecklistItems()['review']['label']);
    $this->assertSame('invalidate', $job->getTriggersConfiguration()['replacement']['action']['configuration']['dependency_action']);
    $this->switchTab(['label' => 'Discard this label'], 'Triggers');
    $this->switchTab([
      'triggers[replacement][action][configuration][dependency_action]' => 'activate',
    ], 'Checklist');
    $this->clickLink('configure');
    $this->submitForm(['label' => 'Discard this item title'], 'Update');
    $this->submitForm([], 'Discard changes');
    $this->assertSession()->pageTextContains('Review replacement work');
    $this->assertSession()->pageTextNotContains('Discard this item title');
    $this->switchTab([], 'Settings');
    $this->assertSession()->fieldValueEquals('label', 'Draft label');
    $this->switchTab([], 'Triggers');
    $this->assertSession()->fieldValueEquals('triggers[replacement][action][configuration][dependency_action]', 'invalidate');
  }

  /**
   * Viewing and staging a clean version do not create a live dirty override.
   */
  public function testExplicitVersionSave(): void {
    $version = $this->container->get('task_job.version_resolver')->createVersion($this->saved(), '6');
    $version->save();
    $dirty_id = JobVersionId::buildDirty('follow_up', '6');
    $this->drupalGet('/admin/config/task/job/' . $version->id() . '/edit/settings');
    $this->assertNull($this->saved($dirty_id));
    $this->switchTab(['description' => 'Working version'], 'Checklist');
    $this->assertNull($this->saved($dirty_id));
    $this->submitForm([], 'Save');
    $this->assertSession()->pageTextContains('The job has been saved.');
    $this->assertSame('Working version', $this->saved($dirty_id)->get('description'));
    $this->assertNotSame('Working version', $this->saved($version->id())->get('description'));
    // Direct component links for the clean version must use the same override.
    $this->drupalGet('/admin/config/task/job/' . $version->id() . '/checklist/add/simply_checkable');
    $this->submitForm(['name' => 'follow', 'label' => 'Follow the override'], 'Add');
    $this->assertSession()->pageTextContains('Follow the override');
    $this->assertSame([], $this->saved($dirty_id)->getChecklistItems());
    $this->submitForm([], 'Save');
    $this->assertArrayHasKey('follow', $this->saved($dirty_id)->getChecklistItems());
    $this->assertSame([], $this->saved($version->id())->getChecklistItems());
  }

  /**
   * Configuration imports cannot be silently overwritten by an older draft.
   */
  public function testChangedConfiguration(): void {
    $this->drupalGet('/admin/config/task/job/follow_up/edit/settings');
    $this->submitForm(['label' => 'My working label'], 'Apply to draft');
    $this->saved()->set('label', 'Imported label')->save();
    $this->submitForm([], 'Save');
    $this->assertSession()->pageTextContains('The saved job changed while you were editing.');
    $this->assertSame('Imported label', $this->saved()->label());
    $this->assertSession()->fieldValueEquals('label', 'My working label');
    $this->submitForm([], 'Discard changes');
    $this->assertSession()->fieldValueEquals('label', 'Imported label');
  }

  /**
   * One administrator cannot read or overwrite another's working copy.
   */
  public function testDraftOwnership(): void {
    $this->drupalGet('/admin/config/task/job/follow_up/edit/settings');
    $this->submitForm(['label' => 'Private working label'], 'Apply to draft');
    $this->drupalLogin($this->drupalCreateUser(['administer task jobs']));
    $this->drupalGet('/admin/config/task/job/follow_up/edit');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Private working label');
    $this->assertSame('Follow up', $this->saved()->label());
  }

}
