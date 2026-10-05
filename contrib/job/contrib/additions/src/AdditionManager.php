<?php

namespace Drupal\task_job_additions;

use Drupal\checklist\ChecklistResolver;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\task\Entity\Task;
use Drupal\task_checklist\TaskChecklistRequestStorageInterface;
use Drupal\task_job\JobExecutionAuthorization;
use Drupal\task_job\JobChecklistExpansion;
use Drupal\task_job\JobVersionResolverInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Shared, permission-checked addition service for HTML and optional HTTP APIs.
 */
class AdditionManager {

  public function __construct(
    protected EntityTypeManagerInterface $entities,
    protected ChecklistResolver $resolver,
    protected JobVersionResolverInterface $versions,
    protected AdditionStorage $storage,
    protected JobExecutionAuthorization $authorization,
    protected AccountProxyInterface $account,
    protected Connection $database,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    protected TaskChecklistRequestStorageInterface $requests,
  ) {}

  /**
   * Reloads and authorizes the task before looking up templates or receipts.
   */
  protected function prepare(Task $task): array {
    if ($task->isNew() || !$this->account->isAuthenticated() || !$this->account->hasPermission('add task checklist templates')) {
      throw new AccessDeniedHttpException('You cannot add checklist work to this task.');
    }
    $account = $this->entities->getStorage('user')->loadUnchanged($this->account->id());
    if (!$account || !$account->isActive()) {
      throw new AccessDeniedHttpException('An active account is required.');
    }
    $uuid = $task->uuid();
    $task = $this->entities->getStorage('task')->loadUnchanged($task->id());
    if (!$task || $task->uuid() !== $uuid) {
      throw new AccessDeniedHttpException('The task is unavailable.');
    }
    $checklist = $this->resolver->resolve($task, 'checklist', 0, 'update');
    if (in_array($task->get('status')->value, [Task::STATUS_RESOLVED, Task::STATUS_CLOSED], TRUE)) {
      throw new AccessDeniedHttpException('Completed tasks cannot receive additional work.');
    }
    $type = $checklist->getType();
    if ($type->getPluginId() !== 'job' || isset($type->getConfiguration()['default_items'])) {
      throw new AccessDeniedHttpException('Only the task job can supply additional work.');
    }
    $this->entities->getStorage('task_job')->resetCache();
    $job = $this->versions->load((string) $task->get('job')->target_id, $task->get('job_version')->value);
    if (!$job || $job->id() !== $type->getJob()?->id() || (string) ($type->getConfiguration()['job_version'] ?? '') !== (string) $task->get('job_version')->value) {
      throw new AccessDeniedHttpException('The task job is unavailable or does not match the checklist.');
    }
    return [$task, $job, $checklist];
  }

  /**
   * Lists authored choices and addition receipts under current task access.
   */
  public function discover(Task $task): array {
    [$task, $job] = $this->prepare($task);
    $templates = [];
    foreach ($job->get('checklist_templates') ?: [] as $name => $definition) {
      if (!empty($definition['allow_addition']) && !empty($definition['items'])) {
        $templates[$name] = ['label' => trim($definition['addition_label'] ?? '') ?: $definition['label']];
      }
    }
    return ['templates' => $templates, 'additions' => array_values($this->storage->forTask($task->uuid()))];
  }

  /**
   * Adds an instance per request UUID without accepting executable config.
   */
  public function add(Task $task, string $template, string $request_id): array {
    if (!Uuid::isValid($request_id) || strtolower($request_id) !== $request_id) {
      throw new \InvalidArgumentException('Use a lowercase UUID as the addition request ID.');
    }
    [$task] = $this->prepare($task);
    // Serialize additions with whole-task processing and other additions.
    $lock_name = 'task_checklist:' . $task->uuid();
    if (!$this->lock->acquire($lock_name, 60)) {
      throw new ConflictHttpException('The task checklist is being processed. Try again.');
    }
    try {
      [$task, $job, $checklist] = $this->prepare($task);
      $existing = $this->storage->load($request_id);
      if ($existing) {
        if ($existing['task_uuid'] !== $task->uuid() || $existing['template'] !== $template || (int) $existing['actor'] !== (int) $this->account->id()) {
          throw new ConflictHttpException('The request ID belongs to a different addition.');
        }
        return $existing;
      }
      $definitions = $job->get('checklist_templates') ?: [];
      if (empty($definitions[$template]['allow_addition']) || empty($definitions[$template]['items'])) {
        throw new AccessDeniedHttpException('This template is not available for staff addition.');
      }
      if (count($this->storage->forTask($task->uuid())) >= 100) {
        throw new ConflictHttpException('This task has reached its addition limit.');
      }
      // Grant approval is required before even queuing delegated child work.
      $expanded = JobChecklistExpansion::instance($template, $definitions, AdditionDefinitions::prefix($request_id));
      foreach ($expanded as $definition) {
        if (($definition['execution']['mode'] ?? 'self') === 'context') {
          $this->authorization->approvedGrant($job);
          break;
        }
      }
      $receipt = [
        'id' => $request_id,
        'task_uuid' => $task->uuid(),
        'job' => $job->getBaseJobId(),
        'job_version' => (string) $job->getVersion(),
        'template' => $template,
        'actor' => (int) $this->account->id(),
        'created' => $this->time->getCurrentTime(),
      ];
      $transaction = $this->database->startTransaction();
      try {
        try {
          $this->storage->insert($receipt);
        }
        catch (IntegrityConstraintViolationException $exception) {
          throw new ConflictHttpException('The addition request ID is already in use.', $exception);
        }
        // Resolve from the recorded source before persisting any new work. This
        // also checks namespace collisions and the aggregate definition limit.
        $checklist = $checklist->getType()->getChecklist($task, 'checklist');
        foreach ($checklist->getItems() as $name => $item) {
          if (str_starts_with($name, AdditionDefinitions::prefix($request_id)) && $item->isNew() && empty($item->get('derivation')->first()?->getValue()['requirements'])) {
            $item->save();
          }
        }
        $this->requests->request((int) $task->id(), $task->uuid(), (int) $this->account->id());
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }
      unset($transaction);
      return $receipt;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

}
