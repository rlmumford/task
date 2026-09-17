# Task

Reusable tasks for Drupal 10/11, extracted from `rlmumford/common` on `2.x`.
The source of truth remains the common repository; the `rlmumford/task` GitHub
repository is an automated split output.

Enable `task_job` for jobs, task contexts, and programmable checklists. Enable
the separate `service` and `note` modules for work containers and comments.
The supporting packages are `rlmumford/checklist`, `plugin_reference`,
`typed_data_plus`.

## Behavior

- A task is one unit of work; a job configures its triggers, checklist and resources.
- A service groups work. The task's `root` groups a chain and its comments;
  it is distinct from the service tree and from dependency ordering.
- Explicit assignees take precedence. Jobs can default to an active service
  manager, an active task creator, or no assignee. The `task.select_assignee`
  event remains the extension point for more sophisticated assignment rules.
- Stored `pending` means a future start or a pending module gate; `waiting`
  means blocked work. Both are derived and automatically re-evaluated. Postpone
  work by moving its start date. Cron queues due pending/waiting tasks, and the
  worker rechecks them. Resolving a prerequisite also rechecks its dependents.
  Queue scans rotate in batches of 100.
- Notes on a task inherit its root and service references.
- Interactive checklist routes and submissions require update access to the
  containing entity. Task permissions distinguish assigned and all tasks.

This is the initial reusable foundation, not a complete port of Drupal 7
CounselKit. Its legal-specific checklist handlers, smart board, recurrence,
assignment groups/condition expressions, resource-pane UX, and full audit
semantics remain follow-up work. No existing CounselKit task data is migrated.

## Resolution timestamps

Saving a task with status `resolved` fills a missing `resolved` timestamp using
current time, including direct field writes and status changes made by presave
hooks. Repeated `resolve()` calls preserve its existing time unless an explicit
replacement time is supplied. Explicit helper timestamps are converted to UTC
without modifying the caller's date object.

Reopening to a non-terminal status clears the current resolution timestamp;
resolving again records the new time. Legacy `closed` retains a known resolution
time but does not invent one, and remains distinct from `resolved` for dependencies.
This uses the existing timestamp field; durable resolution history remains planned.

## Task readiness

`\Drupal::service('task.readiness')->evaluate($task)` returns a
`TaskReadinessResult` with a `state` and all `reasons`. Supply the current task; referenced dependencies and the
immediate service are reloaded from storage. The evaluator does not save entities.

Precedence is resolved/closed, then a consumer invalidation, then pending
(future start or draft immediate service), then waiting (unresolved/missing dependency, or non-active
or missing immediate service), otherwise active. Due dates and deadlines do not
block execution. Only `resolved` satisfies a dependency: **closed dependencies
now block work**, unlike the earlier implementation. Ancestors never gate tasks.
No service reference means no service gate.

The stored non-terminal `status` is a projection of readiness: active → `active`,
pending → `pending`, waiting → `waiting`. It is recomputed on save and can lag
reference changes between saves. Runtime readiness is authoritative for processing.
There is no manual waiting flag: setting `waiting` alone cannot hold a task;
move `start` into the future instead. Completing a blocker cannot release work
before that start date.

Modules contribute gates by subscribing to `Drupal\task\Event\TaskReadinessEvent`.
Subscribers are services tagged `event_subscriber`, with dependencies injected
through their constructors. Register `TaskReadinessEvent::class` in
`getSubscribedEvents()` and contribute from a typed handler:

```php
public function onReadiness(TaskReadinessEvent $event): void {
  if (!$this->approval->isApproved($event->getTask())) {
    $event->addReason('waiting', 'example_approval_required');
  }
}
```

`addReason($state, $code, $details)` accepts active, pending, waiting, or invalid,
a non-empty reason code, and optional diagnostics. Add nothing or an active
reason when ready. Contributions are additive and propagation cannot be stopped;
subscriber order does not determine the final readiness state. Do not mutate the
task or perform side effects from a subscriber. The evaluator owns precedence.

Service's `ServiceTaskReadinessSubscriber` owns the immediate-service gate and
is registered only when Task is enabled. Task has no service-specific gate logic.

An `invalid` contribution recommends resolving the task with resolution `invalid`.
Evaluation stays read-only; task saving and the checklist processor apply this
recommendation and set the resolution timestamp. Repeated processing preserves
terminal outcomes. Cancelled services default to `waiting`; consumer modules can
recommend `invalid` for work no longer needed, while retaining cleanup tasks.

The checklist processor reloads the task and requires active readiness before
processing. Cron's worker also reloads the current task, preserving postponed
starts and terminal states. Pending and waiting work is reconsidered as the
schedule, dependencies, or contributing modules' gates change.

Readiness is a point-in-time check, not an access check or an execution lock.
The caller must enforce permissions and execution identity. Do not cache results
or expose referenced IDs in reasons without checking access. Durable execution
claims, per-item gates, and interactive action enforcement remain later work.

## Tests

In a Drupal consumer with these modules installed on disk, plus `service`,
`note`, and `drupal/core-dev`:

```sh
SIMPLETEST_DB=mysql://user:pass@localhost/test_db vendor/bin/phpunit \
  --bootstrap "$(pwd)/web/core/tests/bootstrap.php" \
  web/modules/contrib/task/tests/src/Kernel
```

Use a local test database. Kernel tests use isolated prefixed tables.
