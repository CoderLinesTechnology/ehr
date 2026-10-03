/* Settings screens: description counter, logo upload on choose, "unsaved changes" save bar. No dependencies. */
(function () {
  'use strict';

  // Live character counter: <textarea data-counter-for="id"> updates <span id="id" data-max="500">.
  document.querySelectorAll('[data-count-target]').forEach(function (area) {
    var out = document.getElementById(area.getAttribute('data-count-target'));
    if (!out) { return; }
    var max = area.getAttribute('maxlength') || '500';
    function update() { out.textContent = area.value.length + '/' + max; }
    area.addEventListener('input', update);
    update();
  });

  // Choosing a logo submits its own form straight away (a <noscript> button covers no-JS).
  document.querySelectorAll('[data-logo-input]').forEach(function (input) {
    input.addEventListener('change', function () {
      if (input.files && input.files.length && input.form) { input.form.submit(); }
    });
  });

  // The save bar sticks to the bottom of the window once something changed.
  document.querySelectorAll('[data-dirty-form]').forEach(function (bar) {
    var form = document.getElementById(bar.getAttribute('data-dirty-form'));
    if (!form) { return; }
    function mark(e) {
      if (e.target && e.target.form === form) { bar.classList.add('is-dirty'); }
    }
    document.addEventListener('input', mark);
    document.addEventListener('change', mark);
  });
}());
