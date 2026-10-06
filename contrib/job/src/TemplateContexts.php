<?php

namespace Drupal\task_job;

use Drupal\checklist\ChecklistContextMapping;
use Drupal\checklist\Event\ChecklistCollectConfigContextsEvent;
use Drupal\checklist\Event\ChecklistEvents;
use Drupal\Core\Plugin\Context\Context;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Exposes a template's declared inputs while configuring its checklist items.
 */
class TemplateContexts implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ChecklistEvents::COLLECT_CONFIG_CONTEXTS => 'collect'];
  }

  /**
   * Adds definitions without inventing runtime values.
   */
  public function collect(ChecklistCollectConfigContextsEvent $event): void {
    $checklist = $event->getChecklist();
    if (!$checklist instanceof JobConfigurationChecklist || $checklist->template === NULL) {
      return;
    }
    $templates = $checklist->getType()->getJob()->get('checklist_templates') ?: [];
    foreach (ChecklistContextMapping::definitions($templates[$checklist->template]['context'] ?? []) as $name => $definition) {
      $event->addContext($name, new Context($definition));
    }
  }

}
