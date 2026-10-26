<?php

namespace Drupal\task_ui\Form;

use Drupal\checklist\Form\ChecklistItemFormBase;
use Drupal\Core\Form\FormAjaxResponseBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\task\TaskInterface;
use Drupal\task_ui\Ajax\TaskChangedCommand;
use Symfony\Component\HttpFoundation\Request;

/**
 * Refreshes board lists after the existing checklist AJAX forms run.
 */
final class WorkspaceAjaxResponseBuilder implements FormAjaxResponseBuilderInterface {

  /**
   * Constructs the response decorator.
   */
  public function __construct(private readonly FormAjaxResponseBuilderInterface $inner) {}

  /**
   * {@inheritdoc}
   */
  public function buildResponse(Request $request, array $form, FormStateInterface $form_state, array $commands) {
    $response = $this->inner->buildResponse($request, $form, $form_state, $commands);
    $object = $form_state->getFormObject();
    if ($object instanceof ChecklistItemFormBase && !$form_state->hasAnyErrors()) {
      $task = $object->getChecklistItem()?->checklist->checklist->getEntity();
      if ($task instanceof TaskInterface) {
        $response->addCommand(new TaskChangedCommand((string) $task->id(), FALSE, $form['#id']));
      }
    }
    return $response;
  }

}
