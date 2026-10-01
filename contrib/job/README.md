# Job configuration editor

The job editor has separate **Checklist**, **Triggers**, **Contexts**,
**Assignment rules**, and **Settings** tabs. Only the selected tab is built and
rendered. Settings includes the label, description and resources. Assignment
currently exposes the existing default rule. Reusable checklist chunks and richer
assignment rules are separate follow-up work; there is no placeholder template tab.

## Routes and local tasks

Tabs are Drupal local tasks declared in `task_job.links.task.yml`. Checklist uses
the entity's existing `entity.task_job.edit_form` route (`/edit`). The other routes
are declared in `task_job.routing.yml`: `entity.task_job.edit_triggers`,
`entity.task_job.edit_contexts`, `entity.task_job.edit_assignment`, and
`entity.task_job.edit_settings`, at `/edit/triggers`, `/edit/contexts`,
`/edit/assignment`, and `/edit/settings` beneath the job URL.

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
