<?php

namespace Drupal\task_dependency_test\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\flexiform\Form\ConfiguredForm;
use Drupal\task\TaskInterface;

/**
 * Exercises the actual Flexiform HTML host with an editable task context.
 */
class Editor extends ControllerBase {

  /**
   * Builds a dependency editor using the same definition as the API test.
   */
  public function build(TaskInterface $task): array {
    return $this->formBuilder()->getForm(ConfiguredForm::class, [
      'plugin' => 'standard',
      'configuration' => [
        'data' => [
          'task' => [
            'plugin' => 'provided',
            'entity_type' => 'task',
            'bundle' => 'task',
            'save_on_submit' => TRUE,
          ],
        ],
        'components' => [
          'dependencies' => [
            'component_type' => 'task_dependencies',
            'context' => 'task',
          ],
        ],
      ],
    ], ['task' => $task]);
  }

}
