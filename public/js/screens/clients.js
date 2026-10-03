/*
 * Clients list behaviour: bulk selection, the Filters toggle and date inputs. Vanilla, no inline handlers (CSP).
 * Everything is an enhancement: without it the list, search, filters and every link still work.
 */
(function () {
  'use strict';

  var doc = document;
  function $(s, c) { return (c || doc).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || doc).querySelectorAll(s)); }

  /* ---- bulk selection ------------------------------------------------ */
  var all = $('[data-select-all]');
  var rows = $$('[data-row-check]');
  var bar = $('[data-bulk-bar]');
  var count = $('[data-bulk-count]');

  function refresh() {
    var ticked = rows.filter(function (r) { return r.checked; }).length;
    if (all) {
      all.checked = ticked > 0 && ticked === rows.length;
      all.indeterminate = ticked > 0 && ticked < rows.length;
    }
    if (bar) {
      bar.hidden = ticked === 0;
      if (count) { count.textContent = ticked + (ticked === 1 ? ' client selected' : ' clients selected'); }
    }
  }

  if (rows.length) {
    rows.forEach(function (r) { r.hidden = false; r.addEventListener('change', refresh); });
    if (all) {
      all.hidden = false;
      all.addEventListener('change', function () { rows.forEach(function (r) { r.checked = all.checked; }); refresh(); });
    }
    var clear = $('[data-bulk-clear]');
    if (clear) { clear.addEventListener('click', function () { rows.forEach(function (r) { r.checked = false; }); refresh(); }); }
    refresh();
  }

  /* ---- filters panel toggle ------------------------------------------ */
  var toggle = $('[data-filters-toggle]');
  var panel = $('#client-filters');
  var columns = $('[data-clients-columns]');
  if (toggle && panel) {
    var small = window.matchMedia ? window.matchMedia('(max-width: 69.999rem)') : null;
    var narrowed = /[?&](status|location|clinician|records|from|to|mine)=/.test(window.location.search);
    function set(open) {
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (columns) { columns.classList.toggle('is-filters-hidden', !open); }
    }
    // On a phone the panel starts folded away (it would push the list far down) unless a filter is active.
    if (small && small.matches && !narrowed) { set(false); }
    toggle.addEventListener('click', function (event) {
      event.preventDefault();
      set(panel.hidden);
      if (!panel.hidden) { var first = $('select, input', panel); if (first && small && small.matches) { first.focus(); } }
    });
  }

  /* ---- date inputs: text with a "Start date" placeholder, native picker on focus ----- */
  $$('[data-date-input]').forEach(function (input) {
    input.addEventListener('focus', function () {
      input.type = 'date';
      if (input.showPicker) { try { input.showPicker(); } catch (e) { /* needs a user gesture */ } }
    });
    input.addEventListener('blur', function () { if (!input.value) { input.type = 'text'; } });
    if (input.value) { input.type = 'date'; }
  });
})();
