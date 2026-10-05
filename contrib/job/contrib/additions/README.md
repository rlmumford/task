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
