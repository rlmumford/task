# Ordered personal work

Enable `task_work_queue` alongside `task_ui`. **Open next task** appears in the work-list navigation and on tasks assigned to the viewer. `/tasks/next` redirects to the ordinary task workspace; checklist forms still submit to that task's canonical URL.

Selection follows these lanes:

1. **Interrupter:** explicit `work_interrupter`, or a deadline within `work_estimate` seconds plus the configured warning interval (15 minutes by default). Oldest start first, then task ID.
2. **Flow:** tasks on the same immediate `service` as the context task. Highest urgency first, then task ID. Sites without service references simply skip Flow.
3. **Plan:** remaining assigned tasks, highest urgency first, then task ID.

The context is the task just worked on, supplied by its next-task link. It must still belong to the viewer and be viewable; its service must also be viewable. Completed tasks can supply context. Opening next excludes the context task even if unfinished; it does not complete, claim, reserve or reassign anything. The list navigation starts a fresh selection without case context. There is no persisted active-case preference.

## Urgency

The defaults port the platform's time/priority calculation. For elapsed days since each date, take the greatest of zero, `min(20, 10 + start days)` and `min(200, 100 + 10 × due days)`. Multiply by priority (low 1, medium 1.25, high 1.5), waiting status (2), and presence of a deadline (2). Add the intrinsic urgency of all open downstream dependents visible to the viewer, then round. Diamonds count each descendant once and cycles terminate without counting a task twice.

`task_work_queue.settings` contains these weights, warning interval and a maximum graph size (5,000 tasks). The task fields `work_priority`, `work_estimate` (remaining seconds), and `work_interrupter` are available to existing EntityTemplates and configurable form displays. Job definitions can populate them through their existing task template. This module does not introduce another job definition type.

The platform's high-risk case multiplier, root/subservice Flow expansion, manual queue ordering and snooze controls are not included. Integrations should not assume this implements every legacy queue feature.

## Access and readiness

Queries respect task query access. Individual view access is checked before using records for ranking. Only tasks assigned to the current active user are candidates. Immediately before returning a recommendation, the selector reloads the task, checks view/update access and evaluates live readiness (including dependencies, schedules and service gates). Stored pending/waiting status does not prevent a now-ready task from being selected.

The request ranks the complete bounded candidate/dependency graph, not just a page of results. Exceeding the bound produces an explicit message and a My work link, never a partial recommendation. GET is read-only and uncached. Concurrent requests can recommend the same task; this is a personal recommendation, not a reservation system. Another user's tasks may contribute urgency when visible, but cannot be selected.

The existing Views lists remain useful for searching, team oversight and choosing work manually. Drupal Queue API workers that execute automation are separate from these staff work lanes.
