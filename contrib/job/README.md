# Job configuration editor

The job editor has separate **Checklist**, **Triggers**, **Contexts**,
**Checklist templates**, **Assignment rules**, and **Settings** tabs. Only the selected tab is built and
rendered. Settings includes the label, description and resources. Assignment
currently exposes the existing default rule; richer assignment rules are follow-up
work. Named checklist templates support static inclusion in this slice.

## Routes and local tasks

Tabs are Drupal local tasks declared in `task_job.links.task.yml`. Checklist uses
the entity's existing `entity.task_job.edit_form` route (`/edit`). The other routes
are declared in `task_job.routing.yml`: `entity.task_job.edit_triggers`,
`entity.task_job.edit_contexts`, `entity.task_job.edit_assignment`, and
`entity.task_job.edit_settings`, at `/edit/triggers`, `/edit/contexts`,
`/edit/assignment`, and `/edit/settings` beneath the job URL. The template tab uses
`entity.task_job.edit_templates` at `/edit/templates` for Add, with a secondary
local task for each template at `/edit/templates/{template}`
(`entity.task_job.edit_template`). These links reflect the current draft, including
unsaved templates and labels, without caching them in shared plugin discovery.

Each route uses the same entity edit form and update access check. Its
`_job_section` default selects the fields to build. There is no `section` query
parameter or separate form-button navigation. Drupal renders and themes the tabs.

## Working draft and save

All tabs and child configuration forms use `task_job.tempstore_repository`.
With JavaScript enabled, following a tab link or opening an off-canvas editor
first validates and retains the current tab's fields without saving configuration.
Validation errors keep the editor on the current tab. **Apply to draft** does the
same without leaving the tab. Without JavaScript (or before opening a tab in a
new browser window), use Apply to draft before following a link.
Nested Entity Template edits are folded into the same job draft when it is next
loaded. Closing a dialog without submitting it does not apply that dialog's edits.

**Save** persists the whole job. **Discard changes** clears the whole working copy,
including nested trigger-template edits. A component dialog's Add or Update button
only modifies the draft. Dialogs return to their originating tab.

For a clean named version, viewing or staging edits does not create a live dirty
override. Save creates that override, or updates an existing dirty version.
Publishing is still a separate operation in the job version list. Tasks continue
using persisted configuration until Save; the unsaved editor draft is never used
by the runtime version resolver.

The shared tempstore has one owner per job configuration ID. Another administrator
receives an access-denied response rather than seeing or replacing that draft.
Discard releases it; otherwise normal shared-tempstore expiry applies. The saved
baseline is retained with the draft. If configuration changes externally, Save
keeps the draft and reports a conflict instead of overwriting the imported change.
There is no automatic merge or lock-takeover UI in this slice.

## Extending the editor

Keep job and component configuration in the working copy. Do not call `save()`
from a tab switch, component form or GET request. Use the repository's edit URL to
return child editors to the active tab. Trigger template adapters use the stable
single-template key `default` for nested routes, regardless of an imported UUID.

Contexts use core `ContextDefinitionInterface` and preserve the existing config
schema; entity contexts are created with core's entity-aware factory. Runtime
context mapping remains handled by the core context-handler service, including
Typed Data Plus's override when enabled.

`TaskJobEditFormTest` covers cross-tab and child-form changes, Save and Discard,
clean-version editing, configuration conflicts and draft ownership. The existing
checklist Entity Template authoring tests cover nested item plugin configuration.
See repository `docs/screenshots/task-trigger-actions` for real browser evidence.

## Named checklist templates

On **Checklist templates**, use the **Add** secondary tab to add a machine name
and label. Each template has its own secondary tab, reusing the main checklist
table builder and item dialogs. Changes stay in the shared job draft as you
switch templates. Use the existing
checklist item chooser and configuration dialogs to build the group. On
**Checklist**, select groups to include. Definitions and references belong to the
same job configuration, draft and named version. A template cannot be removed
from the draft while the Checklist tab still references it.

```yaml
checklist_templates:
  appointment:
    label: Appointment preparation
    items:
      confirm:
        name: confirm
        label: Confirm appointment
        handler: simply_checkable
        handler_configuration: {}
checklist_includes:
  - appointment
```

`getChecklistItems()` returns default items; `getChecklistItems('appointment')`
returns that template's items. `setChecklistItems()` updates the corresponding
in-memory definition. `getExpandedChecklistItems()` returns the default items
followed by selected templates in reference order. It does not write item entities.
Missing templates, repeated inclusions and duplicate item names fail explicitly;
the Save button validates this before committing. Configuration imports use the
same runtime check, so a bad reference cannot silently yield a completed checklist.
Item plugin dependencies are collected from every template, including unused ones.

Static inclusion uses the existing global item namespace. No condition-string or
context-mapping rewriting occurs. Included items' expected outcomes participate
in configuration contexts; when editing an unused template, its own items are
also available. Existing item conditions still control applicability,
actionability and requiredness. Items do not acquire a separate template gate.

Tasks pinned to version 6 resolve the templates from version 6 (including its
saved dirty override), not version 7. Unpersisted items pick up current definitions
when the checklist is loaded again. Already persisted items retain their existing
configuration, state and outcomes, including when a template is deselected; this
slice does not silently remove historical work or change the existing item
snapshot semantics. Tempstore changes never reach task execution until Save.

This is the static authoring foundation for P5. Repeated scoped instances,
nested templates, conditional/decision-driven expansion, generated local names,
and orphan reconciliation/restoration remain separate work. A template is local
to its job; this does not introduce a site-wide template config entity.

## Ordered assignment rules

The **Assignment rules** tab has a sortable table and off-canvas rule editors.
Each rule has a label, an optional native Drupal condition plugin configuration,
and `context_mapping.assignee`. The condition editor supports condition strings,
core conditions, TRUE/FALSE and nested AND/OR/XOR/XAnd groups. Omitting a condition
means the rule always matches. Rules live in the same job draft and named version
as the checklist and triggers. Add, edit, reorder and remove change the draft;
only Save writes configuration, and Discard restores the saved job.

On saving an unassigned task, evaluate rules in their stored order. The first
matching rule wins. Resolve its assignee through the core `context.handler`
service, extended by Typed Data Plus. Assign only an active, authenticated user.
If the matched rule's account is missing or blocked, leave the task unassigned;
do not run another rule or the fallback. If no rule matches, retain the existing
`assignment` policy: service manager, task creator, or leave unassigned.
Existing task assignments and accounts selected by higher-priority subscribers
are preserved. Clearing an assignee allows selection again on the next save;
editing a job does not reassign already assigned tasks.

The available contexts are `task`, plus each declared job context as
`task_context:NAME`. A simple `NAME` alias is also supplied for condition strings,
except that `task` always means the task entity. Caller-supplied contexts are fixed
sources, including within nested condition groups: the condition editor does not
ask users to map them again, and stored mappings cannot redirect them. Actual
plugin inputs (such as User Role’s user input) and the assignee use the enhanced
Typed Data Plus autocomplete widget when context assignment is enabled, supporting
property paths and filters. Definitions come from the edited
job during configuration; runtime values come from that task. Global providers
remain available through `@provider:context` mappings. For example:

```yaml
assignment: service_manager
assignment_rules:
  urgent_review:
    label: Urgent work to the reviewer
    condition:
      id: condition_string
      condition_string: 'task.title.value matches "/urgent/i"'
      negate: false
    context_mapping:
      assignee: 'task_context:reviewer'
  routine_work:
    label: Other work to the creator
    context_mapping:
      assignee: 'task.creator.0.entity'
```

Rule keys are stable identifiers (the editor generates UUIDs); sequence order is
evaluation order. To use the service manager directly in a rule, map to
`task.service.entity.manager.entity`. Autocomplete suggests selectors; property paths and filters can also be entered
directly. Current user means the
account performing the save, including a worker's execution account, so use a
stable task/job context when assignment must not depend on the caller.

A task with `job_version` resolves that version's saved dirty overlay for both
assignment rules and task-context definitions. Without `job_version`, the existing
direct job-reference behavior is retained. Unsaved job-editor drafts are not used
for live task assignment. Missing required condition inputs mean no match;
invalid plugin configuration or invalid selectors raise an error rather than
silently falling back to a different person. Condition plugin dependencies are
included in the job's configuration dependencies.

`AssignmentRulesTest` covers real task saves, first-match order, default fallback,
blocked/missing accounts, explicit assignments, core conditions, groups, global
contexts, pinned versions, dirty overrides and unsaved authoring contexts.
`TaskJobEditFormTest::testAssignmentRuleEditor` covers the editor, shared drafts,
reordering, removal, Save and Discard. Existing jobs need no data migration:
`assignment_rules` defaults to an empty sequence.

## Add checklist template item

The automatic `add_checklist_template` handler activates a reusable job checklist
immediately after its calling item. Configure it through **Add Checklist Item →
Add checklist template**, select a template, update its inputs, and map the
contexts declared on that template. The mapping belongs to this item; the input
definitions belong to the template. The template does not need an exposed addition
button or the optional additions module.

For example, a template named `review` declaring a string input named `subject`
can be invoked with:

```yaml
expand_review:
  label: Add reference review
  handler: add_checklist_template
  handler_configuration:
    template: review
    context_mapping:
      template_context:subject: checklist:entity.title.value
```

Items inside `review` consume `template_context:subject` through their own context
mappings. Separate invocations can map different sources. Local outcome references
inside each template resolve within that invocation, including nested invocations.
Mappings resolve live values; they do not snapshot the task's input data.

The expanded definitions are known before execution, allowing expected outcomes
to be configured. Child identities use
`<parent>__template__<template>__<local_item>`. Children become applicable only once
the parent completes with its `template` outcome naming that template. Ordinary
item conditions still apply. The existing executor commits completion and outcome
together, records an attempt and history, and supports the usual inline/worker
processing. Automatic children can finish in the same processing pass.

Replaying or retrying an invocation reuses its child identities rather than adding
another copy. Different calling items have independent children, outcomes and
history. Tasks keep reading their named job version, including its dirty override,
so fixes to unfinished template items remain visible without recreating the task.
Changing a template or item machine name changes its identity.

Required unmapped inputs block execution. Missing templates, recursive expansion,
unknown mapping destinations and oversized expansion are rejected. Delegated
children require local job execution approval before activation; each child also
passes the normal execution authorization checks when it runs. This handler does
not grant permissions supplied by a caller.

By default this is one invocation per calling item. Collection repetition is
configured as described below. See `AddChecklistTemplateTest` for runnable examples of
scope isolation, nesting, retries, authorization and named/dirty versions, and
`TaskJobEditFormTest::testTemplateExpansionDraft` for configuration persistence.

### Collections and combinations through ordinary context mappings

Map a collection into a single-value template input to invoke that template once
per member. There is no separate loop input or repeat setting. Cardinality is
inferred from the mapped typed-data definition, including filtered selectors.

For example, a Review Document template can declare single-value `document` and
`reviewer` inputs, then be invoked with:

```yaml
review_documents:
  label: Prepare document reviews
  handler: add_checklist_template
  handler_configuration:
    template: review_document
    context_mapping:
      template_context:document: task_context:documents
      template_context:reviewer: task_context:reviewers
```

Two documents and three reviewers produce all six document/reviewer combinations.
A scalar mapping contributes one value without creating another dimension. An input
explicitly declared multiple-value receives its whole collection and does not
multiply invocations. If any iterated collection is empty, no child work is created.

Each invocation has independent contexts, outcomes and history. Its template can
contain an ordinary manual Review Document item, decisions, forms or resource
configuration. A title such as
`Review Document: {{template_context:document.filename.value}} — {{template_context:reviewer}}`
shows which combination the item concerns.

The configuration UI identifies inferred collection mappings as **For each … as
Document**, **For each … as Reviewer**, and so on. Changing the selected template
refreshes its inputs automatically. The rebuild-only Update template inputs button
uses `js-hide`, remaining available when JavaScript is disabled. Neither refresh
commits the job draft.

Successful expansion atomically stores the participating input names in `iterated`,
each frozen collection in a typed `members_<input>` outcome, and its child items.
Each collection is stored once, not once per combination. Entity values use Typed
Data Reference's entity references; they are not serialized entity snapshots.
Source reordering, additions or removals cannot retarget existing work. Entity
fields remain live. Non-iterated mappings, including whole-collection inputs,
continue to resolve their normal current values.

Child names include their parent's name and their position in the **recorded**
Cartesian product: `<parent>__member_<position>__<template>__<item>`. Duplicate
members produce distinct invocations. Without any iterated inputs the original
single-invocation namespace is retained. Child definitions remain available in the
authoring catalog; runtime children materialize under the parent's atomic result.
This avoids creating every possible child before the collections are known.

Replay reuses identities. A failure before commit leaves no partial child work;
a fresh retry can read corrected source data. The completed parent retains its
invocation configuration; child configuration still follows the task's named job
version and dirty override. Use new item names or job versions when changing the
shape of already-executed invocations. Nested invocations are supported.

Each collection is limited to 1,000 members, the Cartesian product to 1,000
combinations, and the expanded checklist to 1,000 items including other work.
These checks happen before children are saved. Unsaved or inaccessible entity
members are rejected. Existing execution authorization and worker processing apply.
No new receipt table, module or endpoint is required.

`AddChecklistTemplateTest` covers Cartesian products, whole-collection inputs,
empty sets, entity references, frozen membership, nesting, rollback/retry and
named/dirty versions. The draft browser test covers mapping persistence. See the
[real UI captures](../../../../../docs/screenshots/checklist-template-collection/README.md).
