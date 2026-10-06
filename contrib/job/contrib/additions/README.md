# Add reusable job work to an existing task

Enable `task_job_additions`. In a job's **Checklist templates** tab, open a
named template and select **Expose template as an addition**. Set an optional **Addition label** for the
Add work selector; leave it blank to use the template label.
The option is part of the shared job draft: Apply keeps it in the draft; Save
publishes it alongside the rest of that job version.

Grant staff `add task checklist templates`. They also need task view/update and
checklist field view/edit access. The interactive checklist display then shows a
collapsed **Add work** form in the checklist action pane. Select a template and
submit to create a separate instance. Resolved and closed tasks reject additions.
No arbitrary item-definition editor is exposed to the executing staff member.

Each addition has a UUID, template name, original logical job/version, initiating
user, and timestamp in `task_job_checklist_addition`. Root items and a durable task
processing request are saved in the same database transaction. The task itself
is not resaved from an old form snapshot. Queued processing rechecks task
readiness and access; pending/waiting tasks retain the work until actionable.
The addition shares the task worker lock with other additions and whole-task
queue processing. This is not a general lock on arbitrary task entity saves.

## Definitions and outcomes

The receipt references the task's current named job version, including its dirty
overlay. It does not duplicate the template's configuration. Unfinished items read
later fixes to that same version; completed items retain their recorded
configuration and outcomes. A different job/version does not adopt an old
addition. As with other job work, removing a definition makes its recorded items
inactive while retaining receipts; restoring the definition reuses them.
Disabling the addition option only stops new additions, not existing instances.

Two additions of `reference_check` use different item namespaces:
`added_<UUID without hyphens>__<local item name>`. Nested decision branches use
the existing scoped expansion. Inside each instance, `item:review:decision` and
`items.review.outcomes.decision` refer to that instance's review outcome, not a
similarly named item in another instance. Existing job/task contexts are inherited.
Caller-supplied context overrides are not supported by this slice.

Limits are 100 additions per task and 1,000 total expanded definitions, alongside
the existing recursion/name-length limits. Invalid expansion or persistence
failure rolls back the receipt, root items and new processing request. Task
deletion removes its addition receipts.

## Execution authorization

Adding work is not permission to choose an executor. The template supplies the
canonical handler and execution policy. Delegated work requires the current job's
local approval before addition; an imported configuration alone is insufficient.
The item execution service rechecks approval and executor access when it submits
or resumes an attempt. The receipt records who added the work; the attempt keeps
its initiator, executor and job authorizer as distinct identities. Later processing
may be initiated by another authorized task save without rewriting the receipt.

## Compact task controls

Available additions appear in an **Other Actions** dropbutton. Each named action
submits directly through Drupal Form API, keeping its CSRF protection and the
same server-side availability/access checks as the API.

Up to five additions fit in the menu. With more choices, the first four remain
there and **Do something else** opens an inline chooser in the checklist action
area. The chooser lists all available additions and has Add and Cancel actions.
Opening or cancelling it does not persist a checklist item or addition receipt.
The chooser also works without JavaScript through an ordinary form rebuild.

## Optional HTTP API

Enable `task_job_additions_api` to expose:

- `GET /task/{task}/checklist/additions?_format=json`: authorized template names
  and labels, plus recorded addition receipts.
- `POST` to the same URL: add work or return the existing receipt for that request.

POST uses a logged-in session, `Content-Type: application/json`, and the normal
Drupal `X-CSRF-Token` obtained from `/session/token`. Its complete body is:

```json
{
  "request_id": "bf28fa6a-f8d2-414a-bb46-b1eb327f096e",
  "template": "reference_check"
}
```

Only these two keys are accepted. Generate a lowercase UUID once per intended
addition and reuse it when retrying that request. A different UUID means a new
instance. A UUID cannot be reused for another task, template or initiating user.
Both first creation and successful replay return HTTP 200 with the receipt.
Access is rechecked on replay; a task resolved in the meantime returns 403.

Malformed input returns 400; denied access or unapproved work returns 403; an
occupied worker lock, conflicting UUID or addition limit returns 409. Refresh
choices after an access/configuration change. A 409 caused by active processing
can be retried using the same request UUID. Discovery and receipt responses are
private and uncached. HTML uses this same addition service. It generates a request UUID for the form
and posts it back as an idempotency key, so a repeated POST remains harmless even
after the successful form cache is discarded. The UUID does not confer access.

## Verification

Kernel tests cover independent outcomes, nested decisions, idempotency, access,
clean/dirty version resolution, completed receipts, disabled/removed definitions,
transaction rollback, and local delegated execution approval/revocation. Browser
tests cover the shared configuration draft, runtime addition, permission-based UI,
optional route installation, CSRF, payload rejection and HTTP replay. Both suites
run in the existing task package SQLite/MySQL CI jobs.

## Availability conditions

An exposed template can have an **Addition availability** condition. Its native
Drupal condition plugin form uses the same contexts and Typed Data Plus selector
widget as checklist configuration. No condition means always available, subject
to the existing permission, task access and execution-approval checks.

For example, offer another reference check only after a main checklist decision
has requested one:

```yaml
checklist_templates:
  reference:
    label: Reference check
    allow_addition: true
    addition_label: Request another reference
    addition_condition:
      id: condition_string
      condition_string: 'items.reference_needed.outcomes.decision == "yes"'
    items: # Existing checklist item definitions go here.
```

Conditions see the current host as `checklist` / `checklist:entity`, job contexts,
and checklist outcomes. Native plugins use standard context mappings, including
property/filter selectors and global `@provider:context` values. Condition groups
and negation retain their normal semantics. The future addition's own items do
not exist yet: the availability editor therefore exposes the containing job's
contexts, not that template's future local outcomes.

UI and API discovery omit unavailable additions. The workspace refreshes its
Add work control alongside normal checklist row refreshes, including after an
AJAX decision and during existing progress polling. Unchanged choices retain the
current selection and request UUID. This does not introduce a separate timer for
arbitrary external data changes. Creation reloads the task and
checks the condition again, so a stale displayed option or an earlier API response
cannot bypass a changed rule. Missing required context values do not match, even
for a negated condition. Invalid configuration/plugin failures remain errors;
they do not default to allowing the addition.

A rule becoming false neither cancels existing additions nor stops their items.
An already recorded request UUID can still be replayed without creating more
work, subject to the ordinary task/access checks. The condition controls new
additions only; configure applicability/actionability on the items themselves if
the work must also depend on continuing conditions. Clean/dirty job version
resolution applies to the availability rule as well as the item definitions.
Condition-plugin dependencies are included in the job's exported dependencies
and execution approval fingerprint through the existing dependency collector.

The browser tests cover configuring, retaining and clearing the rule through the
job draft, native context selection and API rejection after discovery. Kernel
tests cover outcome-driven visibility, global user contexts, missing values,
stale task objects, dirty overrides, idempotent replay and existing work staying
active after availability changes. Row refresh tests also verify that choices
appear/disappear and that unchanged choices retain a stable refresh signature.
See the [real UI walkthrough](../../../../../../../docs/screenshots/job-addition-availability/README.md).

## Template inputs and addition buttons

The **Template contexts** table summarises each template input: machine name,
label, typed-data type, requiredness and cardinality. **Add template input** and
**Edit** open an off-canvas form. Updates remain in the shared job draft until
**Save**; removing an input also clears its addition button mappings. Items consume these as
`template_context:<name>`. Normal `task_context:<name>` inputs remain available
separately; declaring a template input does not change the job's contexts.

The **Exposure** table lists addition buttons. **Add addition button** and **Edit**
open an off-canvas form for the label, enabled state, availability condition and
context mappings. Condition-selection rebuilds stay inside the same panel.
**Update button** writes the working draft; the job’s **Save** commits it. Multiple buttons can invoke the
same template with different inputs. Mappings use the standard Typed Data Plus
assignment widget, including property/filter selectors and global providers.

```yaml
checklist_templates:
  reference:
    label: Reference check
    context:
      subject:
        type: string
        label: Reference subject
        required: true
        multiple: false
    exposures:
      default:
        enabled: true
        label: Check task title
        context_mapping:
          'template_context:subject': 'checklist:entity.title.value'
      description:
        enabled: true
        label: Check task description
        context_mapping:
          'template_context:subject': 'checklist:entity.description.value'
    items: # Handlers consume template_context:subject.
```

Discovery identifies the default button by the template name (`reference`) and
other buttons as `template:button` (`reference:description`). Pass that discovery
key as the existing API `template` value. Receipts record the template and button
separately. Reusing a request UUID for a different button is a conflict.
Disabling a button prevents new additions but keeps existing instances working.
Existing single-button configuration remains readable and is converted when its
template is saved in the editor; existing receipts acquire the `default` button
identity through the database update.

Mappings resolve in the enclosing task scope before local outcome aliases are
installed. They apply to the addition and nested decision branches without
changing host or sibling contexts. Decision choices own their own mappings into
the selected template; use **Update template inputs** after selecting a template.
A future expansion/looping item will likewise own its mappings, not the template.
Looping is not implemented here.

Selectors resolve current values on each iteration. A global current-user source
means the account active during evaluation; use an explicit entity reference when
identity must remain stable. Missing required inputs block the items, including
handlers without their own context slots. Availability is evaluated against the
parent task before the addition exists.

Definitions and mappings belong to the named job version, including dirty
corrections, and participate in its execution approval fingerprint. The API
accepts no caller-supplied mappings or execution-policy overrides. Editing the
configuration never authorizes a different executor by itself.

Kernel coverage includes independent buttons, missing required inputs, scoped
nested branches, live selectors, global providers, pinned/dirty versions and
delegated execution. Browser coverage verifies declaring inputs, native mapping
widgets, multiple buttons, draft tab navigation and explicit Save.
