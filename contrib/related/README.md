# Related work through contexts and outcomes

Enable `task_related` for a shared **Related tasks** View and **Related records**
in task pages and inline workspaces. This module has no document dependency.
Documents, cases, services and other records can use the same integration.

## Authoritative relationships

Typed task `context` values and checklist item `outcomes` supply entity references.
The item's existing `checklist` host determines its task. Ordinary task reference
fields pointing to opted-in types are also contexts. The UI shows the declared
context/outcome label, property name, and originating checklist item title/name.
Collection references preserve their collection label, such as “Created records”.
An outcome can refer to an existing reviewed record; it does not imply creation.

Targets are identified by entity type **and** ID, including string IDs. Task
summaries load targets in batches per type, group them by entity type, and show
records without canonical routes as text. Missing or inaccessible targets are
omitted. The paginated View shows active and completed tasks once each. Removed
references disappear; no independent link history or revision claims are stored.

`typed_reference_index` supplies a rebuildable reverse lookup for nested typed
values. Ordinary reference fields use their own SQL storage. Labels and checklist
ownership are read from saved entities, never inferred from the index.

## Opting into record pages

Set the entity-type property `task_related` to `TRUE` in `hook_entity_type_build()`
or `hook_entity_type_alter()`. The generic module then supplies a configurable
`related_tasks` extra display field for its content-entity bundles. The document
module does this with a single entity-type setting; it has no task integration
module, query service or separate View.

This opt-in also identifies ordinary task references to that type as contexts.
Assignee/owner references are therefore excluded unless their target type is
explicitly opted in. Typed contexts/outcomes are always inspected regardless of
this display setting. To embed the generic View directly, pass one contextual
argument of the form `entity_type:entity_id` to `task_related_tasks` / `embed`.
The argument resolves and checks the target before querying tasks.

## Access and extension points

Task query access and consumer organization restrictions apply before pagination.
Rendering checks task and target access, source field visibility and checklist
item action-state/outcome visibility. Both directions preserve these boundaries.

- `hook_task_ui_task_summary_alter(&$build, $task)` provides the existing summary
  extension point; `task_related` adds grouped related records there.
- `hook_task_related_compatible_alter(&$allowed, $task, $target)` adds relationship
  constraints during rendering. Combine denials; never replace one with an allow.
- `hook_task_related_query_alter($query, $target)` applies equivalent constraints
  to the task subquery before paging. Its task alias is `related_task`.

CounselKit's `ck_organization_task` implements the organization hooks and validates
nested typed references when saved. Consumers should test both query counts and
rendering, including corrupted index entries and mixed entity types.

The former `document_task_links` prototype has been removed. Fresh installations
should enable `task_related`; no source records or index schema are changed.
