(function (Drupal) {
  'use strict';

  Drupal.AjaxCommands.prototype.taskJobAdditionChoices = function (ajax, response, status) {
    const current = document.querySelector(response.selector);
    if (!current || current.dataset.additionChoices === response.choicesHash) {
      return;
    }
    return Drupal.AjaxCommands.prototype.insert.call(this, ajax, response, status);
  };
})(Drupal);
