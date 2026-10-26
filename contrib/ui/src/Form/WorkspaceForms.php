<?php

namespace Drupal\task_ui\Form;

use Drupal\checklist\Form\ChecklistCompleteForm;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\task\TaskInterface;
use Drupal\task_ui\Ajax\TaskChangedCommand;

/**
 * Enhances the existing completion form without replacing its submit logic.
 */
final class WorkspaceForms {

  /**
   * Adds progressive AJAX completion to task checklists.
   */
  public static function alter(array &$form, FormStateInterface $state): void {
    if (!$state->getFormObject() instanceof ChecklistCompleteForm || !isset($form['actions']['complete'])) {
      return;
    }
    $task = $state->get('checklist')->getEntity();
    if ($task instanceof TaskInterface) {
      // Embedded forms must POST to their task rather than the board route.
      $form['#action'] = $task->toUrl()->toString();
      // AJAX rebuilds randomize form IDs; keep the replacement target stable.
      $form['#attributes']['data-task-completion'] = $task->uuid();
      $form['actions']['complete']['#ajax'] = [
        'callback' => [static::class, 'complete'],
        'url' => $task->toUrl(),
        'options' => ['query' => ['ajax_form' => 1]],
      ];
      $form['#attached']['library'][] = 'task_ui/workspace';
    }
  }

  /**
   * Leaves errors in the form; successful completion refreshes the open task.
   */
  public static function complete(array &$form, FormStateInterface $state): AjaxResponse {
    $task = $state->get('checklist')->getEntity();
    $selector = 'form[data-task-completion="' . $task->uuid() . '"]';
    if ($state->hasAnyErrors()) {
      $form['messages'] = ['#type' => 'status_messages', '#weight' => -100];
      return (new AjaxResponse())->addCommand(new ReplaceCommand($selector, $form));
    }
    // The completion handler has already saved the authoritative checklist.
    // Remove this form on standalone task pages as well as inside a board.
    return (new AjaxResponse())
      ->addCommand(new ReplaceCommand($selector, ['#plain_text' => t('Task resolved.')]))
      ->addCommand(new TaskChangedCommand((string) $task->id(), TRUE, $form['#id']));
  }

}
