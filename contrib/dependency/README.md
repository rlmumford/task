# Task event dependencies

`task_dependency` adds event subscriptions to an existing task. Each subscription
has a UUID primary key, trigger configuration, action, concrete context binding,
and a system-managed match receipt (`met`, `occurrence`, `met_at`). Bindings live
in a separate field table indexed by entity type, ID and context name. UUIDs guard
against ID reuse. The owning task is also identified by UUID, so templates and
forms can prepare dependencies before the task has an integer ID.

Enable integrations independently:

- `task_dependency_template`: Entity Template `task_dependency` component.
- `task_dependency_flexiform`: Flexiform `task_dependencies` component, including
  API schema/input support and the same HTML widget as the ordinary task form.
- `task_dependency_job`: job trigger adapters using the shared event matchers.

The base dependency module does not depend on Job, Entity Template or Flexiform.
The existing task-only `dependencies` field continues to work alongside the new
`event_dependencies` field. Activation cycle detection spans both fields.

## Trigger contexts are authoritative

The event plugin declares its required entity context with Drupal's standard
`context_definitions`. `task.resolved` declares `task: entity:task`; the generic
state-event deriver creates a concrete definition for each entity type. Job
adapters copy these definitions from the shared event plugin. The editor builds
its entity selector and label from the selected definition, and Entity Template
uses those same named contexts in `context_mapping`. API inputs also derive the
entity type server-side. There is no independent target-type setting.

The resolved entity type remains in the stored binding for indexing and identity
checks. The initial entity-save event source supports one bound entity context;
multiple correlation contexts need an extended source/matching contract.

## Actions and event semantics

`activate` is the default action. Every activation dependency must be met before
that gate permits activation. Start dates, the immediate service and other
readiness subscribers still apply. An unmet `invalidate` subscription does not
block activation. Any matched invalidation subscription contributes `invalid` to
TaskReadiness: the task resolves with resolution `invalid`. This takes precedence
over waiting gates. Resolved/closed tasks retain their existing result.

Built-in triggers:

- `task.resolved`: context `task`; terminal task resolution. An already-resolved
  target qualifies when the subscription is registered or explicitly retargeted.
- `entity.state:ENTITY_TYPE`: context `entity`; configuration `field`, `property` (default
  `value`), and scalar `value`. Matches a transition into that value after the
  dependency was saved. Creation in that value and unchanged saves do not match.
  It reads the first field item. Use a single-valued status field.

These are **remembered occurrences**, not continuously evaluated prerequisites.
Approval followed by reversal retains its receipt. Two receipts do not prove that
both conditions were true simultaneously. Use an explicit invalidation rule when
reversal should invalidate the task. Continuous or simultaneous-group policies
remain separate future work; conditions are deliberately not included here.

Matches and dispatch intent are recorded inside the source entity transaction.
Registration and source observation lock the same target identity. Rollback drops
the match, audit entry and wake-up. Cron reserves committed requests and queues
`task_dependency_reevaluate`; the worker reloads and saves unfinished tasks, letting
the normal readiness and checklist-processing lifecycle run. Source actors do not
become checklist executors. Expired reservations are redispatched; acknowledgments
remove only the exact request UUIDs processed, preserving newer notifications.

Dependency audit records are separate from checklist attempts. They contain
registration, match, replacement and removal identities, actor and timestamp,
without serialized target entities. No public history endpoint is added here.

## Template example: request now, wait for approval

Enable `task_dependency_template`. Add this component to the task creation
blueprint of the normal document-request job trigger:

```yaml
approval:
  id: task_dependency
  trigger: entity.state:document
  action: activate
  field: approval_status
  property: value
  value: approved
  context_mapping:
    entity: document
```

Here `document` is a saved entity supplied by the trigger's template context;
replace the entity type, selector and field with the site's actual definitions.
`context_mapping` is resolved by the core context handler, including Typed Data
Plus selectors when its context-assignment submodule overrides that service.
Only the resolved identity is persisted. The task save also saves its dependency.
A component can instead use `action: invalidate` and `value: cancelled`.

`TaskDependencyIntegrationTest::testTriggerCreatesDependency()` exercises this
through the ordinary `entity_op:entity_test.insert` job trigger and template
builder, using a test entity in place of a document.

The optional job adapters are `dependency_event:task.resolved` and
`dependency_event:entity.state:ENTITY_TYPE`. The latter stores matcher settings
under `event_configuration` (`field`, `property`, `value`). They share matcher
code with subscriptions but retain the existing job creation/access path. Custom
state adapter configuration is currently supplied in exported job configuration;
this slice does not add a state-matcher configuration panel to the job editor.

## Editing in Flexiform and through its API

```yaml
data:
  task:
    plugin: provided
    entity_type: task
    bundle: task
    save_on_submit: true
components:
  dependencies:
    component_type: task_dependencies
    context: task
```

HTML and API edits update working data. Only the form's normal save step writes
the task/dependencies; Cancel or abandoning a form does not register subscriptions.
API input is an array of rows with `id` (existing dependency UUID, omitted for a
new row), `trigger`, `action`, `entity_id`, optional `field`,
`property`, and `value`. `met` is never accepted as
input. Unchanged rows retain their UUID and receipt, irrespective of JSON key
order. Changed definitions get a new UUID and waiting boundary. Removed owned
records are deleted on task save, with their audit retained.

Task/field editing access, target visibility and watched-field visibility are
checked. Generic entity endpoints cannot mutate dependency records. Currently an
inaccessible/deleted target prevents opening the shared editor; a trusted repair
can remove the reference from the owning task. There is no target-label disclosure
fallback. Dependencies are current operational data, not revision snapshots;
configuration changes must be saved on the default task revision.

## Explicit replacement following

A source integration calls:

```php
\Drupal::service('task_dependency.manager')->retarget($old, $replacement, $dependency_uuids);
```

Call synchronously within the source replacement transaction, **before** the
replacement's qualifying transition. The caller selects the dependency UUIDs to
move; an empty selection moves nothing. `task_dependency` storage's
`watching($entity_type, $id)` method provides the indexed candidates for that
workflow to filter. There is no replacement-following flag on a dependency. The
requirement/action stays unchanged; the old receipt is cleared, the indexed
binding changes atomically and history records old/new identities. Late old-target
updates no longer match. Task activation cycles and replacement loops are rejected;
replacement chains are limited to 64 hops. Terminal owners are not changed.

This API does not infer successors from cancellation or pick the newest event.
It does not replay historical generic state transitions which happened before
retargeting, nor represent replacement-pending/ambiguous states. Integrations that
resolve successors asynchronously need a durable occurrence/replacement protocol
before adopting this API. Already-resolved task replacements are the explicit
terminal-resolution exception described above.

## Validation and visual review

Kernel tests cover exact identity, registration boundaries, rollback, remembered
approval, invalidation precedence, terminal preservation, cycles, replacement,
ordinary job-trigger creation, Flexiform API working data, and the automatic
activation → checklist → resolution chain. Existing task tests continue to run in
the normal task suite on SQLite and MySQL.

`task_dependency_test` is a test-only module. On a disposable development site,
enable test extension discovery and the module, then open
`/task/{task}/dependency-editor` as a user who can edit that task. This hosts the
actual Flexiform plugin, useful for browser review of saved and embedded forms.
See the screenshots and reproduction notes in `docs/screenshots/task-dependencies`.
