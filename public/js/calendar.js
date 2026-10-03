/* Calendar screen: date picker button and "click an empty slot to book". No inline script (CSP script-src 'self'). */
(function () {
  'use strict';

  // Date jump: the native date input sits invisibly over the calendar icon; picking a date reloads the view there.
  document.querySelectorAll('[data-cal-goto]').forEach(function (input) {
    input.removeAttribute('tabindex');
    input.addEventListener('change', function () {
      var form = input.form;
      if (!form || !input.value) { return; }
      var date = form.querySelector('input[name="date"]');
      if (date) { date.value = input.value; }
      input.disabled = true; // keep "goto" out of the URL
      if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
    });
  });

  // Click an empty part of a column to start a booking at that time (15-minute steps).
  document.querySelectorAll('.cal-grid[data-create-url]').forEach(function (grid) {
    var startHour = parseInt(grid.getAttribute('data-start-hour'), 10) || 0;
    grid.addEventListener('click', function (event) {
      var col = event.target.closest ? event.target.closest('.cal-col') : null;
      if (!col || event.target.closest('a')) { return; }
      var rect = col.getBoundingClientRect();
      var rowHeight = rect.height / (parseInt(getComputedStyle(grid).getPropertyValue('--rows'), 10) || 1);
      var minutes = Math.floor(((event.clientY - rect.top) / rowHeight) * 4) * 15;
      var total = startHour * 60 + minutes;
      var params = new URLSearchParams();
      params.set('date', col.getAttribute('data-date'));
      params.set('time', String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0'));
      if (col.getAttribute('data-clinician')) { params.set('clinician', col.getAttribute('data-clinician')); }
      window.location.href = grid.getAttribute('data-create-url') + '?' + params.toString();
    });
  });
})();
