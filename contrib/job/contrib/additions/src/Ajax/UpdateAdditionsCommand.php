<?php

namespace Drupal\task_job_additions\Ajax;

use Drupal\Core\Ajax\ReplaceCommand;

/**
 * Replaces the addition form only when its available choices changed.
 */
class UpdateAdditionsCommand extends ReplaceCommand {

  /**
   * Constructs the update, including normal form assets and Drupal behaviors.
   */
  public function __construct(string $selector, array $content, protected string $choicesHash) {
    parent::__construct($selector, $content);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $command = parent::render();
    $command['command'] = 'taskJobAdditionChoices';
    $command['choicesHash'] = $this->choicesHash;
    return $command;
  }

}
