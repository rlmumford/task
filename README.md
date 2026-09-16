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
- Tasks with future start dates or unresolved dependencies are pending.
  Drupal cron queues due tasks, and the `task_scheduled` worker rechecks them.
  Resolving a prerequisite also rechecks its dependents. `waiting` is a manual
  hold and is not automatically released. Queue scans rotate in batches of 100.
- Notes on a task inherit its root and service references.
- Interactive checklist routes and submissions require update access to the
  containing entity. Task permissions distinguish assigned and all tasks.

This is the initial reusable foundation, not a complete port of Drupal 7
CounselKit. Its legal-specific checklist handlers, smart board, recurrence,
assignment groups/condition expressions, resource-pane UX, and full audit
semantics remain follow-up work. No existing CounselKit task data is migrated.

## Tests

In a Drupal consumer with these modules installed on disk, plus `service`,
`note`, and `drupal/core-dev`:

```sh
SIMPLETEST_DB=mysql://user:pass@localhost/test_db vendor/bin/phpunit \
  --bootstrap "$(pwd)/web/core/tests/bootstrap.php" \
  web/modules/contrib/task/tests/src/Kernel
```

Use a local test database. Kernel tests use isolated prefixed tables.
