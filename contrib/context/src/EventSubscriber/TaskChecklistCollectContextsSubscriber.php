<?php

namespace Drupal\task_context\EventSubscriber;

use Drupal\checklist\Event\ChecklistCollectContextsEventInterface;
use Drupal\checklist\Event\ChecklistEvents;
use Drupal\Core\Plugin\Context\Context;
use Drupal\task\Entity\Task;
use Drupal\typed_data_reference\TypedDataDefinitionToContextDefinitionTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber to expose task context to checklists.
 */
class TaskChecklistCollectContextsSubscriber implements EventSubscriberInterface {
  use TypedDataDefinitionToContextDefinitionTrait;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[ChecklistEvents::COLLECT_CONFIG_CONTEXTS][] = [
      'addTaskContextsToChecklistContext',
      50,
    ];
    $events[ChecklistEvents::COLLECT_RUNTIME_CONTEXTS][] = [
      'addTaskContextsToChecklistContext',
      50,
    ];

    return $events;
  }

  /**
   * Add any task contexts to the checklists contexts stack.
   *
   * @param \Drupal\checklist\Event\ChecklistCollectContextsEventInterface $event
   *   The checklist collect contexts event.
   */
  public function addTaskContextsToChecklistContext(ChecklistCollectContextsEventInterface $event) {
    $entity = $event->getChecklist()->getEntity();
    if ($entity instanceof Task && $entity->hasField('context')) {
      foreach ($entity->get('context')->getPropertyDefinitions() as $context_name => $data_definition) {
        $event->addContext(
          "task_context:{$context_name}",
          new Context(
            $this->contextDefinitionForDataDefinition($data_definition),
            $entity->get('context')->get($context_name)->getValue()
          )
        );
      }
    }
  }

}
