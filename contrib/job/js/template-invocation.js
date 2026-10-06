(function (Drupal, once, $) {
  Drupal.behaviors.taskJobTemplateInvocation = {
    attach(context) {
      once('template-input-refresh', '[data-template-input-refresh]', context).forEach((select) => {
        select.addEventListener('change', () => {
          const container = select.closest('[data-template-invocation]');
          $(container.querySelector('[data-template-input-update]')).trigger('mousedown');
        });
      });
    }
  };
})(Drupal, once, jQuery);
