<?php

namespace Drupal\Tests\task_job\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests for the job edit page.
 *
 * @package Drupal\Tests\task_job\Functional
 */
class TaskJobEditFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user', 'options', 'datetime', 'task', 'task_job', 'task_checklist',
    'entity_template', 'views',
  ];

  /**
   * Test the job edit form.
   */
  public function testJobEditForm() {
    $user = $this->createUser(['administer task jobs']);
    $this->drupalLogin($user);

    $label = $this->randomString();
    $id = strtolower($this->randomMachineName());
    $this->drupalGet('/admin/config/task/job/add');
    $this->assertSession()->buttonExists('Create & Configure');
    $page = $this->getSession()->getPage();
    $page->fillField('edit-label', $label);
    $page->fillField('edit-id', $id);
    $page->fillField('edit-description', $this->randomString());
    $page->pressButton('Create & Configure');

    $this->assertSession()->addressEquals('admin/config/task/job/' . $id . '/edit');
    $this->assertSession()->pageTextContains('Edit ' . $label);

    $this->assertSession()->pageTextNotContains('You have unsaved changes.');
    $this->assertSession()->buttonNotExists('Cancel');
    $this->assertSession()->linkExists('Add Checklist Item');
    $this->getSession()->getPage()->clickLink('Add Checklist Item');
    $this->assertSession()->linkExists('Simple Checkbox');
    $this->getSession()->getPage()->clickLink('Simple Checkbox');

    $this->assertSession()->fieldExists('Name');
    $this->assertSession()->fieldExists('Title');

    $page = $this->getSession()->getPage();
    $ci_name = $this->randomMachineName(4);
    $page->fillField('edit-name', $ci_name);
    $page->fillField('edit-label', $this->randomString());
    $this->assertSession()->buttonExists('Add');
    $page->pressButton('Add');

    $this->assertSession()->pageTextContains('You have unsaved changes.');
    $this->assertSession()->pageTextContains($ci_name);

    $this->assertSession()->buttonExists('Cancel');
    $this->getSession()->getPage()->pressButton('Cancel');
    $this->assertSession()->pageTextNotContains('You have unsaved changes.');
    $this->assertSession()->pageTextNotContains($ci_name);
    $this->assertSession()->buttonNotExists('Cancel');
  }

}
