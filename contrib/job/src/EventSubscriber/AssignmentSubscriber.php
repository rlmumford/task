<?php

namespace Drupal\task_job\EventSubscriber;

use Drupal\task\Event\SelectAssigneeEvent;
use Drupal\task\Event\TaskEvents;
use Drupal\task_job\AssignmentRules;
use Drupal\task_job\JobVersionResolverInterface;
use Drupal\user\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies a job's assignment rule before the service-level fallback.
 */
class AssignmentSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(protected JobVersionResolverInterface $versions, protected AssignmentRules $rules) {}

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
    if (!$task->assignee->isEmpty() || $event->getAssignee() || $task->job->isEmpty()) {
      return;
    }
    $job = $task->get('job_version')->isEmpty()
      ? $task->job->entity
      : $this->versions->load($task->job->target_id, $task->get('job_version')->value);
    if (!$job) {
      return;
    }
    $contexts = $job->get('assignment_rules') ? $this->rules->contexts($job, $task) : [];
    foreach ($job->get('assignment_rules') ?: [] as $rule) {
      if (!$this->rules->matches($rule, $contexts)) {
        continue;
      }
      $account = $this->rules->assignee($rule, $contexts);
      if ($account instanceof User && $account->isAuthenticated() && $account->isActive()) {
        $event->setAssignee($account);
      }
      // First match wins, even if its account is missing or blocked.
      $event->stopPropagation();
      return;
    }
    if ($job->get('assignment') === 'service_manager') {
      return;
    }
    if ($job->get('assignment') === 'creator') {
      $creator = $task->creator->entity;
      if ($creator && $creator->isAuthenticated() && $creator->isActive()) {
        $event->setAssignee($creator);
      }
    }
    // "Unassigned" (and an unknown rule) must not fall through to the service
    // manager. Other policies can be supplied by higher-priority subscribers.
    $event->stopPropagation();
  }

}
