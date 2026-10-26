(function ($, Drupal, once, drupalSettings) {
  const boards = new WeakMap();

  function createBoard(element) {
    const panel = element.querySelector('[data-task-panel]');
    const content = panel.querySelector('#task-work-content');
    const status = panel.querySelector('[data-task-status]');
    const queue = element.querySelector('.task-work-queue');
    let selected;
    let generation = 0;
    let opener;
    let refreshTimer;

    function dirty() {
      return !!content.querySelector('form[data-task-unsaved]');
    }

    function canLeave() {
      return !dirty() || window.confirm(Drupal.t('Leave this task and discard unsaved input?'));
    }

    function clear() {
      Drupal.detachBehaviors(content, drupalSettings, 'unload');
      content.replaceChildren();
    }

    function load(task, summary = false) {
      const version = ++generation;
      const url = new URL(task.url, window.location.origin);
      if (summary) {
        url.searchParams.set('summary', '1');
      }
      panel.hidden = false;
      element.classList.add('task-work-board--open');
      status.textContent = summary ? '' : Drupal.t('Loading task…');
      const ajax = Drupal.ajax({url: url.toString(), httpMethod: 'GET', progress: false});
      const success = ajax.success;
      ajax.success = function (response, code) {
        if (version !== generation || !element.isConnected) {
          return Promise.resolve();
        }
        return Promise.resolve(success.call(this, response, code)).then(() => {
          status.textContent = '';
          if (!summary) {
            content.querySelector('[data-task-heading]')?.focus({preventScroll: true});
          }
        });
      };
      ajax.error = function (xhr) {
        if (version !== generation) {
          return;
        }
        if ([403, 404].includes(xhr.status)) {
          clear();
        }
        status.textContent = Drupal.t('The task could not be loaded. Check your access or try opening it again.');
      };
      ajax.options.timeout = 30000;
      const complete = ajax.options.complete;
      ajax.options.complete = function (xhr, code) {
        if (code === 'timeout') {
          ajax.error(xhr);
        }
        return complete.call(this, xhr, code);
      };
      ajax.execute().always(() => { Drupal.ajax.instances[ajax.instanceIndex] = null; });
    }

    function refreshViews() {
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(() => {
        if (!element.isConnected) {
          return;
        }
        // Do not race a user's Views filter/pager request or checklist submit.
        if ($.active) {
          refreshViews();
          return;
        }
        Object.values(Drupal.views.instances || {}).forEach(instance => {
          if (instance.settings.view_name !== 'task_workspace' || !element.contains(instance.$view[0])) {
            return;
          }
          const ajax = instance.refreshViewAjax;
          const applied = JSON.parse(instance.$view[0].dataset.taskViewInput || '{}');
          Object.assign(ajax.options.data, applied);
          const scroll = queue.scrollTop;
          const success = ajax.success;
          ajax.success = function (response, code) {
            // Automatic refresh must not scroll or replace the task panel.
            return Promise.resolve(success.call(this, response.filter(command => command.command !== 'scrollTop'), code))
              .then(() => { queue.scrollTop = scroll; });
          };
          ajax.execute();
        });
      }, 150);
    }

    function click(event) {
      const link = event.target.closest('a[data-task-workspace-url]');
      if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return;
      }
      event.preventDefault();
      if (!canLeave()) {
        return;
      }
      opener = link;
      selected = {id: link.dataset.taskId, url: link.dataset.taskWorkspaceUrl};
      clear();
      load(selected);
    }
    element.addEventListener('click', click);
    panel.querySelector('[data-task-close]').addEventListener('click', () => {
      if (canLeave()) {
        generation++;
        selected = null;
        clear();
        panel.hidden = true;
        element.classList.remove('task-work-board--open');
        if (opener?.isConnected) {
          opener.focus({preventScroll: true});
        }
        else {
          queue.focus({preventScroll: true});
        }
      }
    });
    content.addEventListener('input', event => {
      const form = event.target.closest('form');
      if (form) {
        form.dataset.taskUnsaved = 'true';
      }
    });
    const beforeUnload = event => {
      if (dirty()) {
        event.preventDefault();
        event.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', beforeUnload);
    return {
      changed(response) {
        refreshViews();
        const form = document.getElementById(response.formId);
        if (form) {
          delete form.dataset.taskUnsaved;
        }
        if (selected?.id === response.taskId) {
          // Keep other action forms and resource panes intact after row actions.
          if (!response.reload || !dirty()) {
            load(selected, !response.reload);
          }
          else {
            status.textContent = Drupal.t('Task details changed. Finish your unsaved input, then reopen the task.');
          }
        }
      },
      destroy() {
        generation++;
        clearTimeout(refreshTimer);
        window.removeEventListener('beforeunload', beforeUnload);
        element.removeEventListener('click', click);
      },
    };
  }

  Drupal.behaviors.taskWorkspace = {
    attach(context) {
      once('task-work-board', '[data-task-board]', context).forEach(element => boards.set(element, createBoard(element)));
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.filter('task-work-board', '[data-task-board]', context).forEach(element => {
          boards.get(element)?.destroy();
          boards.delete(element);
        });
      }
    },
  };

  Drupal.AjaxCommands.prototype.taskWorkspaceChanged = function (ajax, response) {
    const elements = document.querySelectorAll('[data-task-board]');
    elements.forEach(element => boards.get(element)?.changed(response));
    if (!elements.length && response.reload) {
      window.location.reload();
    }
  };
})(jQuery, Drupal, once, drupalSettings);
