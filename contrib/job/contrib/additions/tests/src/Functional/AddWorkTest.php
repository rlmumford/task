<?php

namespace Drupal\Tests\task_job_additions\Functional;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests draft authoring, runtime HTML addition, and optional HTTP boundaries.
 *
 * @group task_job_additions
 */
class AddWorkTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'task_job_additions'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalLogin($this->drupalCreateUser([
      'administer task jobs', 'view any tasks', 'update any tasks',
      'add task checklist templates',
    ]));
    Job::create([
      'id' => 'support',
      'label' => 'Support',
      'checklist_templates' => [
        'review' => [
          'label' => 'Review evidence',
          'allow_addition' => FALSE,
          'items' => [
            'review' => [
              'name' => 'review',
              'label' => 'Confirm evidence reviewed',
              'handler' => 'simply_checkable',
              'handler_configuration' => [],
            ],
          ],
        ],
      ],
    ])->save();
    EntityViewDisplay::create(['targetEntityType' => 'task', 'bundle' => 'task', 'mode' => 'default', 'status' => TRUE])
      ->setComponent('checklist', ['type' => 'checklist_interactive', 'label' => 'hidden'])->save();
  }

  /**
   * Draft opt-in is published only on Save, then appears on the task.
   */
  public function testAuthorAndAddWork(): void {
    $url = '/admin/config/task/job/support/edit/templates/review';
    $this->drupalGet($url);
    $this->submitForm([
      'templates[review][allow_addition]' => TRUE,
      'templates[review][addition_label]' => 'Request an evidence review',
    ], 'Apply to draft');
    $storage = $this->container->get('entity_type.manager')->getStorage('task_job');
    $this->assertFalse($storage->loadUnchanged('support')->get('checklist_templates')['review']['allow_addition']);
    $this->clickLink('Settings');
    $this->drupalGet($url);
    $this->assertSession()->checkboxChecked('templates[review][allow_addition]');
    $this->assertSession()->fieldValueEquals('templates[review][addition_label]', 'Request an evidence review');
    $this->submitForm([], 'Save');
    $this->assertTrue($storage->loadUnchanged('support')->get('checklist_templates')['review']['allow_addition']);
    $task = Task::create(['title' => 'Review task', 'job' => 'support', 'start' => '2000-01-01T00:00:00']);
    $task->save();
    $this->drupalGet($task->toUrl());
    $this->assertSession()->buttonExists('Request an evidence review');
    $this->assertSession()->fieldNotExists('template');
    $request_id = $this->assertSession()->elementExists('css', 'input[name=request_id]')->getValue();
    $this->submitForm([], 'Request an evidence review');
    $this->assertSession()->pageTextContains('The checklist work has been added.');
    $this->assertSession()->pageTextContains('Confirm evidence reviewed');
    // A repeated POST keeps the original request ID after the form was rebuilt.
    $this->assertSession()->elementExists('css', 'input[name=request_id]')->setValue($request_id);
    $this->submitForm([], 'Request an evidence review');
    $this->assertCount(1, $this->container->get('task_job_additions.storage')->forTask($task->uuid()));
    $this->submitForm([], 'Request an evidence review');
    $this->assertCount(2, $this->container->get('task_job_additions.storage')->forTask($task->uuid()));
    $this->drupalLogin($this->drupalCreateUser(['view any tasks', 'update any tasks']));
    $this->drupalGet($task->toUrl());
    $this->assertSession()->buttonNotExists('Request an evidence review');
  }

  /**
   * Long lists open a cancellable chooser without adding work on navigation.
   */
  public function testOverflowChooser(): void {
    $job = Job::load('support');
    $template = $job->get('checklist_templates')['review'];
    $template['allow_addition'] = TRUE;
    $templates = [];
    for ($i = 1; $i <= 7; $i++) {
      $templates['review_' . $i] = ['label' => 'Review ' . $i] + $template;
    }
    $job->set('checklist_templates', $templates)->save();
    $task = Task::create(['title' => 'More work', 'job' => $job]);
    $task->save();
    $this->drupalGet($task->toUrl());
    $this->assertSession()->buttonExists('Review 4');
    $this->assertSession()->buttonNotExists('Review 5');
    $this->assertSession()->fieldNotExists('template');
    $this->submitForm([], 'Do something else');
    $this->assertSession()->optionExists('template', 'Review 7');
    $this->assertCount(0, $this->container->get('task_job_additions.storage')->forTask($task->uuid()));
    $this->submitForm([], 'Cancel');
    $this->assertSession()->fieldNotExists('template');
    $this->submitForm([], 'Do something else');
    $this->submitForm(['template' => 'review_7'], 'Add selected work');
    $this->assertSession()->pageTextContains('The checklist work has been added.');
    $receipts = array_values($this->container->get('task_job_additions.storage')->forTask($task->uuid()));
    $this->assertCount(1, $receipts);
    $this->assertSame('review_7', $receipts[0]['template']);
  }

  /**
   * HTTP creation is optional, CSRF protected and rejects execution policy.
   */
  public function testOptionalApi(): void {
    $job = Job::load('support');
    $templates = $job->get('checklist_templates');
    $templates['review']['allow_addition'] = TRUE;
    $job->set('checklist_templates', $templates)->save();
    $task = Task::create(['title' => 'API work', 'job' => $job]);
    $task->save();
    $url = $this->getAbsoluteUrl('/task/' . $task->id() . '/checklist/additions?_format=json');
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(404);
    $this->container->get('module_installer')->install(['task_job_additions_api']);
    $this->rebuildContainer();
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $discovery = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('Review evidence', $discovery['templates']['review']['label']);
    $this->drupalGet('/session/token');
    $token = $this->getSession()->getPage()->getContent();
    $client = $this->getSession()->getDriver()->getClient();
    $payload = ['request_id' => $this->container->get('uuid')->generate(), 'template' => 'review'];
    $headers = ['CONTENT_TYPE' => 'application/json'];
    $client->request('POST', $url, [], [], $headers, json_encode($payload));
    $this->assertSame(403, $client->getResponse()->getStatusCode());
    $headers['HTTP_X_CSRF_TOKEN'] = $token;
    $client->request('POST', $url, [], [], $headers, json_encode($payload + ['executor' => 1]));
    $this->assertSame(400, $client->getResponse()->getStatusCode());
    $client->request('POST', $url, [], [], $headers, json_encode($payload));
    $this->assertSame(200, $client->getResponse()->getStatusCode());
    $receipt = json_decode($client->getResponse()->getContent(), TRUE);
    $this->assertSame($payload['request_id'], $receipt['id']);
    $this->assertSame((int) $this->loggedInUser->id(), $receipt['actor']);
    // A configuration change between discovery and submission is authoritative.
    $templates['review']['addition_condition'] = ['id' => 'condition_constant:false'];
    $job->set('checklist_templates', $templates)->save();
    $client->request('GET', $url);
    $this->assertSame([], json_decode($client->getResponse()->getContent(), TRUE)['templates']);
    $new_payload = ['request_id' => $this->container->get('uuid')->generate(), 'template' => 'review'];
    $client->request('POST', $url, [], [], $headers, json_encode($new_payload));
    $this->assertSame(403, $client->getResponse()->getStatusCode());
    $client->request('POST', $url, [], [], $headers, json_encode($payload));
    $this->assertSame(200, $client->getResponse()->getStatusCode());
    $this->assertCount(1, $this->container->get('task_job_additions.storage')->forTask($task->uuid()));
  }

  /**
   * Availability uses native plugin forms and survives draft tab navigation.
   */
  public function testAvailabilityAuthoring(): void {
    $url = '/admin/config/task/job/support/edit/templates/review';
    $this->drupalGet($url);
    $this->submitForm([
      'templates[review][allow_addition]' => TRUE,
      'templates[review][addition_condition][id]' => 'condition_string',
    ], 'Update condition');
    $this->assertSession()->fieldNotExists('templates[review][addition_condition][settings][context_mapping][checklist]');
    $expression = 'checklist.title.value == "Needs evidence"';
    $this->submitForm([
      'templates[review][addition_condition][settings][condition_string]' => $expression,
    ], 'Apply to draft');
    $storage = $this->container->get('entity_type.manager')->getStorage('task_job');
    $this->assertArrayNotHasKey('addition_condition', $storage->loadUnchanged('support')->get('checklist_templates')['review']);
    $this->clickLink('Settings');
    $this->drupalGet($url);
    $this->assertSession()->fieldValueEquals('templates[review][addition_condition][settings][condition_string]', $expression);
    $this->submitForm([], 'Save');
    $this->assertSame($expression, $storage->loadUnchanged('support')->get('checklist_templates')['review']['addition_condition']['condition_string']);
    $task = Task::create(['title' => 'Needs evidence', 'job' => 'support']);
    $task->save();
    $this->drupalGet($task->toUrl());
    $this->assertSession()->buttonExists('Review evidence');
    $task->set('title', 'Evidence received')->save();
    $this->drupalGet($task->toUrl());
    $this->assertSession()->buttonNotExists('Review evidence');
    $this->drupalGet($url);
    $this->submitForm(['templates[review][addition_condition][id]' => ''], 'Update condition');
    $this->submitForm([], 'Save');
    $this->assertArrayNotHasKey('addition_condition', $storage->loadUnchanged('support')->get('checklist_templates')['review']);
    $this->drupalGet($task->toUrl());
    $this->assertSession()->buttonExists('Review evidence');
    $this->drupalGet($url);
    $this->submitForm(['templates[review][addition_condition][id]' => 'user_role'], 'Update condition');
    $this->assertSession()->elementAttributeContains('css', '[name="templates[review][addition_condition][settings][context_mapping][user]"]', 'data-autocomplete-path', 'typed_data_context_assignment_autocomplete');
  }

}
