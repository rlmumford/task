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
  protected static $modules = ['block', 'task_job', 'task_dependency_job', 'checklist_state_test'];

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
   * Delegation stays in the draft until an authorized configurer saves the job.
   */
  public function testExecutionApprovalOnExplicitSave(): void {
    $author = $this->drupalCreateUser([
      'administer task jobs',
      'authorize delegated checklist execution',
    ]);
    $this->drupalLogin($author);
    $this->drupalGet('/admin/config/task/job/follow_up/checklist/add/single_step_test');
    $this->assertSession()->fieldExists('execution[mode]');
    $this->assertSession()->elementAttributeContains('css', '[name="execution[context_mapping][executor]"]', 'data-autocomplete-path', 'typed_data_context_assignment_autocomplete');
    $this->submitForm([
      'name' => 'work',
      'label' => 'Approved work',
      'execution[mode]' => 'context',
      'execution[context_mapping][executor]' => 'checklist:entity.assignee.entity',
    ], 'Add');
    $this->assertSession()->pageTextContains('Approved work');
    $this->assertSame([], $this->saved()->getChecklistItems());
    $grants = $this->container->get('keyvalue')->get('task_job.execution_authorization');
    $this->assertNull($grants->get('follow_up'));
    $this->submitForm([], 'Save');
    $this->assertSession()->pageTextContains('The job has been saved.');
    $this->assertSame((int) $author->id(), $grants->get('follow_up')['authorizer']);
    $this->assertSame('context', $this->saved()->getChecklistItems()['work']['execution']['mode']);

    // Expire the first author's draft lock before the second editor enters.
    $this->container->get('tempstore.shared')->get('task_jobtask_job')->delete('follow_up');
    $this->drupalLogin($this->drupalCreateUser(['administer task jobs']));
    $this->drupalGet('/admin/config/task/job/follow_up/edit');
    $this->clickLink('configure');
    $this->assertSession()->fieldNotExists('execution[mode]');
    $this->submitForm(['label' => 'Unauthorized change'], 'Update');
    $this->submitForm([], 'Save');
    $this->assertSession()->pageTextContains('requires permission to authorize delegated checklist execution');
    $this->assertSame('Approved work', $this->saved()->getChecklistItems()['work']['label']);
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

  /**
   * Named groups and item dialogs use the same draft and save boundary.
   */
  public function testChecklistTemplates(): void {
    $this->drupalGet('/admin/config/task/job/follow_up/edit/templates');
    $this->submitForm([
      'new_template[name]' => 'appointment',
      'new_template[label]' => 'Appointment preparation',
    ], 'Add template');
    $this->assertSame([], $this->saved()->get('checklist_templates'));
    $this->clickLink('Add Checklist Item');
    $this->clickLink('Simple Checkbox');
    $this->assertStringContainsString('template=appointment', $this->getSession()->getCurrentUrl());
    $this->submitForm(['name' => 'confirm', 'label' => 'Confirm appointment'], 'Add');
    $this->assertSession()->addressEquals('/admin/config/task/job/follow_up/edit/templates/appointment');
    $this->assertSession()->pageTextContains('Confirm appointment');
    $this->clickLink('configure');
    $this->submitForm(['label' => 'Confirm appointment details'], 'Update');
    $this->submitForm([], 'Apply to draft');
    $this->clickLink('Checklist');
    $this->submitForm(['checklist_includes[appointment]' => 'appointment'], 'Apply to draft');
    $this->assertSame([], $this->saved()->getExpandedChecklistItems());
    $this->clickLink('Checklist templates');
    $this->clickLink('Appointment preparation');
    $this->submitForm([], 'Remove template');
    $this->assertSession()->pageTextContains('Remove this template from the Checklist tab before deleting it.');
    $this->submitForm([], 'Save');
    $job = $this->saved();
    $this->assertSame([], $job->getChecklistItems());
    $this->assertSame('Confirm appointment details', $job->getExpandedChecklistItems()['confirm']['label']);
    $this->assertSame(['appointment'], $job->get('checklist_includes'));
    $this->clickLink('configure');
    $this->submitForm(['label' => 'Unsaved replacement label'], 'Update');
    $this->submitForm([], 'Discard changes');
    $this->assertSession()->pageTextContains('Confirm appointment details');
    $this->assertSession()->pageTextNotContains('Unsaved replacement label');
    $this->clickLink('Checklist');
    $this->clickLink('Add Checklist Item');
    $this->clickLink('Simple Checkbox');
    $this->submitForm(['name' => 'confirm', 'label' => 'Conflicting default item'], 'Add');
    $this->submitForm([], 'Save');
    $this->assertSession()->pageTextContains('occurs more than once');
    $this->assertSame([], $this->saved()->getChecklistItems());
    $this->submitForm([], 'Discard changes');
    $this->submitForm(['checklist_includes[appointment]' => FALSE], 'Apply to draft');
    $this->clickLink('Checklist templates');
    $this->clickLink('Appointment preparation');
    $this->submitForm([], 'Remove template');
    $this->assertSession()->pageTextNotContains('Confirm appointment details');
    $this->submitForm([], 'Discard changes');
    $this->clickLink('Appointment preparation');
    $this->assertSession()->pageTextContains('Confirm appointment details');
    $this->clickLink('Add');
    $this->assertSession()->fieldExists('new_template[name]');
    $this->assertSession()->pageTextNotContains('Confirm appointment details');
    $this->submitForm([
      'new_template[name]' => 'follow_up',
      'new_template[label]' => 'Follow-up work',
    ], 'Add template');
    $this->assertSession()->addressEquals('/admin/config/task/job/follow_up/edit/templates/follow_up');
    $this->assertSession()->linkExists('Appointment preparation');
    $this->assertSession()->linkExists('Follow-up work');
    $this->assertSession()->linkExists('Add');
    $this->submitForm(['templates[follow_up][label]' => 'Renamed follow-up'], 'Apply to draft');
    $this->clickLink('Appointment preparation');
    $this->assertSession()->pageTextContains('Confirm appointment details');
    $this->clickLink('Renamed follow-up');
    $this->assertSession()->fieldValueEquals('templates[follow_up][label]', 'Renamed follow-up');
    $this->submitForm([], 'Discard changes');
    $this->assertSession()->addressEquals('/admin/config/task/job/follow_up/edit/templates');
    $this->assertSession()->linkNotExists('Renamed follow-up');
    $this->assertSession()->linkExists('Appointment preparation');

  }

  /**
   * Rule dialogs, order and removal stay in the shared draft until Save.
   */
  public function testAssignmentRuleEditor(): void {
    $this->drupalGet('/admin/config/task/job/follow_up/edit/assignment');
    $this->submitForm([], 'Apply to draft');
    $this->assertSession()->statusCodeEquals(200);
    $this->clickLink('Add assignment rule');
    $this->assertSession()->elementAttributeContains('css', '[name="context_mapping[assignee]"]', 'data-autocomplete-path', 'typed_data_context_assignment_autocomplete');
    $this->submitForm([
      'label' => 'Urgent creator',
      'context_mapping[assignee]' => 'task.creator.0.entity',
      'condition[id]' => 'condition_string',
    ], 'Update condition');
    $this->assertSession()->fieldNotExists('condition[settings][context_mapping][task]');
    $this->submitForm(['condition[settings][condition_string]' => 'task.title.value == "Urgent"'], 'Add rule');
    $this->assertSession()->addressEquals('/admin/config/task/job/follow_up/edit/assignment');
    $this->assertSession()->pageTextContains('Urgent creator');
    $this->assertEmpty($this->saved()->get('assignment_rules'));
    $this->clickLink('Contexts');
    $this->clickLink('Assignment rules');
    $this->clickLink('Configure');
    $this->assertSession()->fieldValueEquals('condition[settings][condition_string]', 'task.title.value == "Urgent"');
    $this->submitForm(['label' => 'Urgent work'], 'Update rule');
    $this->clickLink('Add assignment rule');
    $this->submitForm(['label' => 'Other work', 'context_mapping[assignee]' => 'task.creator.0.entity'], 'Add rule');
    $this->submitForm([], 'Save');
    $rules = $this->saved()->get('assignment_rules');
    $keys = array_keys($rules);
    $this->assertSame(['Urgent work', 'Other work'], array_column($rules, 'label'));
    $this->switchTab([
      'assignment_rules[' . $keys[0] . '][weight]' => 2,
      'assignment_rules[' . $keys[1] . '][weight]' => 0,
    ], 'Settings');
    $this->clickLink('Assignment rules');
    $this->submitForm([], 'Save');
    $this->assertSame(['Other work', 'Urgent work'], array_column($this->saved()->get('assignment_rules'), 'label'));
    $this->submitForm([], 'Remove');
    $this->assertSession()->pageTextNotContains('Other work');
    $this->submitForm([], 'Discard changes');
    $this->assertSession()->pageTextContains('Other work');
    $this->submitForm([], 'Remove');
    $this->submitForm([], 'Save');
    $this->assertSame(['Urgent work'], array_column($this->saved()->get('assignment_rules'), 'label'));
  }

  /**
   * Template expansion is configured in the job draft with declared mappings.
   */
  public function testTemplateExpansionDraft(): void {
    $job = $this->saved();
    $job->set('checklist_templates', [
      'review' => [
        'label' => 'Review work',
        'context' => ['target' => ['type' => 'entity:task', 'label' => 'Review target', 'required' => TRUE]],
        'items' => [
          'confirm' => ['label' => 'Confirm review', 'handler' => 'simply_checkable', 'handler_configuration' => []],
        ],
      ],
    ])->save();
    $this->drupalGet('/admin/config/task/job/follow_up/checklist/add/add_checklist_template');
    $this->submitForm([
      'name' => 'expand',
      'label' => 'Add review work',
      'plugin_configuration[template]' => 'review',
    ], 'Update template inputs');
    $this->submitForm([
      'plugin_configuration[context_mapping][template_context:target]' => 'checklist:entity',
    ], 'Add');
    $this->assertSame([], $this->saved()->getChecklistItems());
    $this->clickLink('Settings');
    $this->clickLink('Checklist');
    $this->clickLink('configure');
    $this->assertSession()->fieldValueEquals('plugin_configuration[template]', 'review');
    $this->assertSession()->fieldValueEquals('plugin_configuration[context_mapping][template_context:target]', 'checklist:entity');
    $this->submitForm([], 'Update');
    $this->clickLink('Checklist templates');
    $this->clickLink('Review work');
    $this->submitForm([], 'Remove template');
    $this->assertSession()->pageTextContains('Remove this template from expansion items before deleting it.');
    $this->submitForm([], 'Save');
    $this->assertArrayHasKey('expand__template__review__confirm', $this->saved()->getExpandedChecklistItems());
  }

  /**
   * A collection selector and its repeated input survive draft navigation.
   */
  public function testCollectionExpansionDraft(): void {
    $job = $this->saved();
    $job->set('context', ['documents' => ['type' => 'entity:task', 'label' => 'Documents', 'multiple' => TRUE]]);
    $job->set('checklist_templates', [
      'review' => [
        'label' => 'Review work',
        'context' => ['target' => ['type' => 'entity:task', 'label' => 'Review target', 'required' => TRUE]],
        'items' => [
          'confirm' => ['label' => 'Confirm review', 'handler' => 'simply_checkable', 'handler_configuration' => []],
        ],
      ],
    ])->save();
    $this->drupalGet('/admin/config/task/job/follow_up/checklist/add/add_checklist_template');
    $this->submitForm([
      'name' => 'expand',
      'label' => 'Add review work',
      'plugin_configuration[template]' => 'review',
    ], 'Update template inputs');
    $this->submitForm([
      'plugin_configuration[context_mapping][template_context:target]' => 'task_context:documents',
    ], 'Add');
    $this->assertSame([], $this->saved()->getChecklistItems());
    $this->clickLink('Settings');
    $this->clickLink('Checklist');
    $this->clickLink('configure');
    $this->assertSession()->fieldValueEquals('plugin_configuration[template]', 'review');
    $this->assertSession()->fieldValueEquals('plugin_configuration[context_mapping][template_context:target]', 'task_context:documents');
    $this->submitForm([], 'Update');
    $this->clickLink('Checklist templates');
    $this->clickLink('Review work');
    $this->submitForm([], 'Remove template');
    $this->assertSession()->pageTextContains('Remove this template from expansion items before deleting it.');
    $this->submitForm([], 'Save');
    $this->assertArrayHasKey('expand__template__review__confirm', $this->saved()->getExpandedChecklistItems());
  }

  /**
   * Decision template selection survives tab navigation and explicit saving.
   */
  public function testDecisionTemplateDraft(): void {
    $job = $this->saved();
    $job->set('checklist_templates', [
      'documents' => [
        'label' => 'Collect documents',
        'context' => ['target' => ['type' => 'entity:task', 'label' => 'Document target', 'required' => TRUE]],
        'items' => [
          'request' => [
            'name' => 'request',
            'label' => 'Request documents',
            'handler' => 'simply_checkable',
            'handler_configuration' => [],
          ],
        ],
      ],
    ])->save();
    $this->drupalGet('/admin/config/task/job/follow_up/checklist/add/decision');
    $this->submitForm([
      'name' => 'review',
      'label' => 'Review evidence',
      'plugin_configuration[question]' => 'What is needed?',
      'plugin_configuration[options][0][name]' => 'more',
      'plugin_configuration[options][0][label]' => 'More documents',
      'plugin_configuration[options][0][template]' => 'documents',
    ], 'Update template inputs');
    $this->assertSession()->fieldExists('plugin_configuration[options][0][context_mapping][template_context:target]');
    $this->submitForm([
      'plugin_configuration[options][0][context_mapping][template_context:target]' => 'checklist:entity',
    ], 'Add');
    $this->assertSession()->addressEquals('/admin/config/task/job/follow_up/edit');
    $this->assertSame([], $this->saved()->getChecklistItems());
    $this->clickLink('Settings');
    $this->clickLink('Checklist');
    $this->clickLink('configure');
    $this->assertSession()->fieldValueEquals('plugin_configuration[options][0][template]', 'documents');
    $this->assertSession()->fieldValueEquals('plugin_configuration[options][0][context_mapping][template_context:target]', 'checklist:entity');
    $this->submitForm([], 'Update');
    $this->clickLink('Checklist templates');
    $this->clickLink('Collect documents');
    $this->submitForm([], 'Remove template');
    $this->assertSession()->pageTextContains('Remove this template from decision choices before deleting it.');
    $this->submitForm([], 'Save');
    $this->assertSame('documents', $this->saved()->getChecklistItems()['review']['handler_configuration']['options']['more']['template']);
    $this->assertArrayHasKey('review__more__documents__request', $this->saved()->getExpandedChecklistItems());
  }

}
