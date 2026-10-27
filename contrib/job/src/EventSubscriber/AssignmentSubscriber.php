<?php

namespace Drupal\task_job\EventSubscriber;

use Drupal\task\Event\SelectAssigneeEvent;
use Drupal\task\Event\TaskEvents;
use Drupal\task_job\AssignmentRules;
use Drupal\task_job\TaskJobResolver;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies the job's ordered rules without an implicit fallback.
 */
class AssignmentSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(protected TaskJobResolver $jobs, protected AssignmentRules $rules) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [TaskEvents::SELECT_ASSIGNEE => ['selectAssignee', 100]];
  }

  /**
   * Selects an active account without replacing an explicit task assignment.
   */
  public function selectAssignee(SelectAssigneeEvent $event): void {
    $task = $event->getTask();
    if (!$task->assignee->isEmpty() || $event->getAssignee()) {
      return;
    }
    $job = $this->jobs->resolve($task);
    if (!$job) {
      return;
    }
    $contexts = $job->getDefinitionValue('assignment_rules') ? $this->rules->contexts($job, $task) : [];
    foreach ($job->getDefinitionValue('assignment_rules') ?: [] as $rule) {
      if (!$this->rules->matches($rule, $contexts)) {
        continue;
      }
      $account = $this->rules->assignee($rule, $contexts);
      if ($account instanceof UserInterface && $account->isAuthenticated() && $account->isActive()) {
        $event->setAssignee($account);
      }
      // First match wins, even if its account is missing or blocked.
      $event->stopPropagation();
      return;
    }
    // Jobs own assignment through their rules. An unconditional last rule
    // supplies a default; no match must not invoke an implicit service policy.
    $event->stopPropagation();
  }

}
