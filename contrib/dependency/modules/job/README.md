# Job trigger actions and replacements

Enable `task_dependency_job` to use the same event definitions for job triggers
and task dependencies. Every job trigger now selects an **Action**. Existing
configuration without an action still creates a task; manual creation also keeps
that behavior. Conditions continue to apply before either action executes.

**Retarget matching dependencies** moves unmet dependencies on unfinished tasks
of this logical job, across its versions. Choose the watched dependency event and
its effect (`activate` or `invalidate`), then map the original and replacement
contexts. The event's context definition determines their entity type. The normal
Typed Data Plus context selector supports related-data paths and global contexts.
Already-met dependencies, other jobs and terminal tasks are left alone.

For example, configure `entity.replaced:task` on the follow-up job:

```yaml
replacement:
  id: 'entity.replaced:task'
  key: replacement
  action:
    plugin: retarget_dependencies
    configuration:
      dependency_trigger: task.resolved
      dependency_action: activate
      context_mapping:
        original: original
        replacement: replacement
```

The workflow which creates replacement work explicitly reports it. Inject the
event dispatcher into that workflow and dispatch **inside its transaction**, after
saving the replacement and before its qualifying transition:

```php
use Drupal\task_dependency\Event\EntityReplacementEvent;

$eventDispatcher->dispatch(new EntityReplacementEvent($original, $replacement));
```

This generates the `entity.replaced:ENTITY_TYPE` job event with two contexts, whose
labels come from the entity definition. A custom event such as `event.rescheduled`
can instead expose its own original/replacement contexts and use the same action.
No event is inferred from arbitrary saves, cancellation statuses or a checkbox on
individual dependencies. The action selects concrete UUIDs and uses the existing
retarget service, preserving access, cycle checks, history and atomic wake-ups.
A second notification finds no remaining matching dependencies to move.

The action matches the dependency event ID and effect; it does not distinguish
configurations within that event (for example, two target state values). A workflow
needing narrower selection can supply an action plugin or call `retarget()` with
its exact UUID selection. This slice does not install phone or calendar adapters.
Generic state transitions before retargeting are not replayed; asynchronous
replacement delivery requires an additional durable event protocol.

## Action extension contract

Implement `TriggerActionInterface`, normally by extending `TriggerActionBase`,
and declare `@JobTriggerAction` under `Plugin/JobTriggerAction`. Use standard
`context_mapping` and plugin configuration forms. `execute($trigger, $save)` runs
after the source trigger's access and conditions pass. It returns **new tasks**,
not tasks edited in place. With `$save = FALSE`, mutation actions must not write;
the creation action can return unsaved task previews. Actions run under the
caller's identity; replacement does not bypass task update or target view access.

Kernel coverage includes preview safety, job/effect/met/terminal selection,
transaction rollback, repeat delivery and activation by the replacement. The real
job editor is also exercised for action switching, event switching, saving and
reloading mappings. See `docs/screenshots/task-trigger-actions` at repository root.

## Explicit occurrences

Sources which are not entity field transitions may implement
`OccurrenceTriggerInterface`. Their `matches()` returns FALSE; trusted source code
calls `task_dependency.manager::recordOccurrence($id, $entity)` inside its database
transaction after establishing the event facts. It records only existing matching
subscriptions and preserves the ordinary identity, locking, history and readiness
queue behavior. Do not expose this method as an unguarded user operation.

The job-event deriver gives these sources their declared context without an
invented `original` entity. The source integration invokes
`dependency_event:SOURCE_ID` with those same contexts; job conditions and actions
still apply. Document Task uses this for completion of required document reviews.
