<?php

namespace Drupal\task_context\EventSubscriber;

use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\task\Event\CollectResourcesContextsEvent;
use Drupal\task\Event\TaskEvents;
use Drupal\typed_data_reference\TypedDataReferenceItemList;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber for task resource events.
 *
 * @package Drupal\task_context\EventSubscriber
 */
class TaskResourceCollectResourcesContextsSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    $events[TaskEvents::COLLECT_RESOURCES_CONTEXTS] = 'collectResourceContexts';
    return $events;
  }

  /**
   * Collect the resources contexts.
   *
   * @param \Drupal\task\Event\CollectResourcesContextsEvent $event
   *   The event.
   */
  public function collectResourceContexts(CollectResourcesContextsEvent $event) {
    $context_field = $event->getTask()->get('context');
    if ($context_field instanceof TypedDataReferenceItemList) {
      foreach ($context_field->getPropertyDefinitions() as $key => $data_definition) {
        $definition_class = ContextDefinition::class;
        $context_class = Context::class;
        if (strpos($data_definition->getDataType(), 'entity:') === 0) {
          $definition_class = EntityContextDefinition::class;
          $context_class = EntityContext::class;
        }

        /** @var \Drupal\Core\Plugin\Context\ContextDefinition $context_definition */
        $context_definition = new $definition_class(
          $data_definition->getDataType(),
          $data_definition->getLabel(),
          $data_definition->isRequired(),
          FALSE,
          $data_definition->getDescription()
        );
        foreach ($data_definition->getConstraints() as $name => $constraint_def) {
          $context_definition->addConstraint($name, $constraint_def);
        }

        $data = NULL;
        try {
          if ($data = $context_field->get($key)) {
            $data = $data->getValue();
          }
        }
        catch (MissingDataException $exception) {
          // Do nothing.
        }

        $event->addContext($key, new $context_class($context_definition, $data));
      }
    }
  }

}
