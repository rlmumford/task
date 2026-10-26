<?php

namespace Drupal\task_ui\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Notifies an open board without changing task state or navigating away.
 */
final class TaskChangedCommand implements CommandInterface {

  /**
   * Constructs a notification for a changed task.
   */
  public function __construct(private readonly string $taskId, private readonly bool $reload = FALSE, private readonly string $formId = '') {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'taskWorkspaceChanged',
      'taskId' => $this->taskId,
      'reload' => $this->reload,
      'formId' => $this->formId,
    ];
  }

}
