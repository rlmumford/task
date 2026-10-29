# Task workspace

`task_ui` is an optional UI for the existing task/checklist system. It provides `/tasks` with My work, Unassigned, Team work and History queues, title search, separate due dates and external deadlines, and a shared `/task/{task}` workspace. It does not record time, create a second workflow, or alter task access policy.

The `task_workspace` View has four embedded displays: `mine`, `unassigned`, `team` and `history`. Views owns the SQL query, exposed title filter, sortable title/due/deadline columns and 25-item pager. Entity API applies the task query-access handler to both rows and counts; do not disable SQL rewriting on this View. The current-user argument supplies the personal display. Computed readiness, context and staff/action columns reuse the task presenter and still check entity access. References are batch-loaded for each page, with independent view access before labels are shown.

The default sort is newest first. Readiness is evaluated live for display; it is not a database-sortable field. Administrators can change the View through Views UI rather than editing PHP list code. The exposed title input is named `title`, because Views reserves `q`.

Team work and History also expose an **Assigned to** filter (`assignee`). Choices
come from distinct assignees of access-checked tasks, with one representative task
loaded per assignee. Names follow the same visibility policy as the board,
including `hook_task_ui_assignee_alter()`. This allows applications to retain
inactive staff as filter choices and conceal people who have left an organization.
No global user directory is exposed. Task access still applies to filtered rows
and pager counts. My work and Unassigned already constrain assignment and do not
show a second assignee filter. Existing sites can add the **Task assignee** Views
filter to their customized displays; installing the module creates these defaults.

With JavaScript, selecting a task opens its existing checklist and resources in a panel beside the View. Filters and pagers refresh the list without replacing the panel. Checklist actions refresh the list and task summary; completion reloads the task. Applied filters, sorting and page survive those automatic refreshes. Switching or closing a task prompts before discarding unsaved form input. Failed requests report an error, and stale loading responses cannot replace a more recently selected task. Without JavaScript, task links still open `/task/{task}`.

The task page and in-place panel render the existing interactive checklist, including its document/resource pane and completion gates. Read-only viewers see checklist state without mutation controls. Due dates and court/external deadlines remain separate fields. No generic status editor is added.

Applications extend presentation with standard Drupal alter hooks:

- `hook_task_ui_context_fields_alter(&$fields)`: opt single-valued entity-reference fields into list and task-summary columns (`field_name => translated label`).
- `hook_task_ui_navigation_alter(&$links)`: add navigation links.
- `hook_task_ui_task_actions_alter(&$actions, $task)`: add authorized render-array actions.
- `hook_task_ui_task_summary_alter(&$content, $task)`: add read-only metadata to the task summary. It refreshes after board actions; do not put forms here.
- `hook_task_ui_assignee_alter(&$label, $task)`: apply application-specific staff-label visibility.

Organization membership and claim/reassignment actions belong to CounselKit's optional `ck_organization_task`. CMS owns its `/user` landing integration and filing/case fields. Installing the base task package alone does not enable these routes.

## Extensions

An extension that changes task data through an AJAX form can append `TaskChangedCommand($task_id, $reload_panel)` to its response. `ck_organization_task` uses this after successful assignment in a Drupal dialog. The workspace decorates the form AJAX response builder to notify the board after existing checklist item forms; it adds an AJAX callback to the existing completion form while preserving that form's validation and submission. No parallel mutation API is introduced.
