/**
 * @file
 * Retains edits before following a local task or opening a nested dialog.
 */
(function (Drupal, once, $) {
  Drupal.behaviors.taskJobEditor = {
    attach(context) {
      once('task-job-tab', 'a[data-task-job-section]', context).forEach((link) => {
        link.addEventListener('click', (event) => {
          const form = document.querySelector('.task-job-editor');
          if (!form || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
          event.preventDefault();
          form.dispatchEvent(new CustomEvent('taskJobNavigate', { detail: link.href }));
        });
      });
      // AJAX validation can pass the replaced form itself as the context.
      const forms = $(context).find('.task-job-editor').addBack('.task-job-editor').get();
      once('task-job-editor', forms).forEach((form) => {
        let pending;
        form.addEventListener('taskJobNavigate', (event) => {
          if (pending) return;
          pending = { url: event.detail, navigate: true };
          $(form.querySelector('.task-job-open-dialog')).trigger('mousedown');
        });
        // Capture before Drupal's link AJAX handler opens the dialog. The form
        // request validates and stores this tab before the dialog reads its job.
        form.addEventListener('click', (event) => {
          const link = event.target.closest('a.use-ajax');
          if (!link || !form.contains(link)) return;
          event.preventDefault();
          event.stopImmediatePropagation();
          if (pending) return;
          pending = {
            url: link.href,
            dialogType: link.dataset.dialogType || 'dialog',
            dialogRenderer: link.dataset.dialogRenderer || 'off_canvas',
            dialog: JSON.parse(link.dataset.dialogOptions || '{}'),
          };
          $(form.querySelector('.task-job-open-dialog')).trigger('mousedown');
        }, true);
        $(form).on('taskJobDraftSaved', () => {
          const options = pending;
          pending = null;
          if (options?.navigate) window.location.assign(options.url);
          else if (options) Drupal.ajax(options).execute();
        });
        // A failed request should leave the original link usable for retry.
        $(document).off('ajaxError.taskJobEditor').on('ajaxError.taskJobEditor', () => { pending = null; });
      });
    },
  };
})(Drupal, once, jQuery);
