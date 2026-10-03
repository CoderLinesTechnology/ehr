/*
 * Client form behaviour (clients/create, clients/edit, and the "Add a new client" form on New appointment).
 * Vanilla, no inline handlers (CSP). Everything is an enhancement: without it every section shows, the type
 * radios decide which apply (the server ignores the rest) and each list renders one spare blank row.
 *
 *  - client type: show only the sections for the chosen type ([data-for-type="adult minor"]);
 *  - repeatable rows (emails, phones, guardians): "Add another" clones the <template>, rows can be removed,
 *    exactly one "Primary" radio stays checked per list;
 *  - couple partners: "Existing client" / "New client" sections, and a type-ahead that fills the client select
 *    from the global search's JSON answer (same origin, the visibility rules of the search itself).
 */
(function () {
  'use strict';

  var doc = document;
  function $(s, c) { return (c || doc).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || doc).querySelectorAll(s)); }
  var LIMITS = { email: 10, phone: 10, guardian: 5 };
  var counter = 0;

  $$('[data-client-form]').forEach(function (form) {
    /* ---- client type ---------------------------------------------------- */
    var choices = $$('[data-type-choice]', form);
    function showType(type) {
      form.setAttribute('data-type', type);
      $$('[data-for-type]', form).forEach(function (section) {
        section.hidden = section.getAttribute('data-for-type').split(' ').indexOf(type) === -1;
      });
    }
    choices.forEach(function (radio) {
      radio.addEventListener('change', function () { if (radio.checked) { showType(radio.value); } });
    });
    var chosen = choices.filter(function (r) { return r.checked; })[0];
    if (chosen) { showType(chosen.value); }

    /* ---- repeatable rows ------------------------------------------------- */
    $$('[data-repeat]', form).forEach(function (list) {
      var kind = list.getAttribute('data-repeat');
      var template = $('template[data-repeat-template="' + kind + '"]', form);
      var add = $('[data-repeat-add="' + kind + '"]', form);
      var radioName = 'primary_' + kind;

      function rows() { return $$('[data-repeat-row]', list); }
      function blank(row) {
        return $$('input[type="text"], input[type="email"], input[type="tel"], input:not([type])', row).every(function (i) { return !i.value.trim(); });
      }
      function refresh() {
        var all = rows();
        all.forEach(function (row) {
          var remove = $('[data-repeat-remove]', row);
          if (remove) { remove.hidden = all.length < 2; }
        });
        if (add) { add.hidden = all.length >= (LIMITS[kind] || 10); }
        var radios = $$('input[type="radio"][name="' + radioName + '"]', list);
        if (radios.length && !radios.some(function (r) { return r.checked; })) { radios[0].checked = true; }
      }

      // The spare row is for visitors without this script: "Add another" replaces it.
      rows().forEach(function (row) {
        if (row.hasAttribute('data-repeat-spare') && blank(row) && rows().length > 1) { row.remove(); }
      });

      if (add && template) {
        add.addEventListener('click', function () {
          var key = 'n' + (++counter) + Date.now().toString(36);
          var html = template.innerHTML.replace(/__KEY__/g, key);
          var holder = doc.createElement('div');
          holder.innerHTML = html.trim();
          var row = holder.firstElementChild;
          list.appendChild(row);
          refresh();
          var first = $('input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"])', row);
          if (first) { first.focus(); }
        });
      }

      list.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-repeat-remove]') : null;
        if (!button || !list.contains(button)) { return; }
        var row = button.closest('[data-repeat-row]');
        var next = row.nextElementSibling || row.previousElementSibling;
        row.remove();
        refresh();
        var focus = next ? $('input:not([type="hidden"])', next) : (add && !add.hidden ? add : null);
        if (focus) { focus.focus(); }
      });

      refresh();
    });

    /* ---- couple partners ------------------------------------------------- */
    var searchUrl = form.getAttribute('data-search-url');
    $$('[data-partner]', form).forEach(function (partner) {
      var modes = $$('[data-partner-mode]', partner);
      function showMode() {
        var mode = (modes.filter(function (m) { return m.checked; })[0] || {}).value || 'existing';
        $$('[data-partner-for]', partner).forEach(function (section) { section.hidden = section.getAttribute('data-partner-for') !== mode; });
      }
      modes.forEach(function (m) { m.addEventListener('change', showMode); });
      showMode();

      var box = $('[data-partner-search]', partner);
      var query = $('[data-partner-query]', partner);
      var select = $('[data-partner-select]', partner);
      if (!box || !query || !select || !searchUrl || !window.fetch) { return; }
      box.hidden = false;
      var status = $('[role="status"]', box);
      var original = select.innerHTML;
      var timer = null;
      var seq = 0;

      function option(value, label) {
        var o = doc.createElement('option');
        o.value = value;
        o.textContent = label;
        return o;
      }

      query.addEventListener('input', function () {
        clearTimeout(timer);
        var term = query.value.trim();
        if (term.length < 2) {
          var keep = select.value;
          select.innerHTML = original;
          select.value = keep;
          if (status) { status.textContent = ''; }
          return;
        }
        timer = setTimeout(function () {
          var mine = ++seq;
          fetch(searchUrl + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { clients: [] }; })
            .then(function (data) {
              if (mine !== seq) { return; }
              var people = (data.clients || []).filter(function (c) { return c.type !== 'couple' && !c.archived; });
              var selected = select.value;
              var selectedText = selected ? (select.options[select.selectedIndex] || {}).textContent : '';
              select.innerHTML = '';
              select.appendChild(option('', people.length ? 'Choose a client' : 'No matching client'));
              if (selected && !people.some(function (c) { return c.id === selected; })) { select.appendChild(option(selected, selectedText)); }
              people.forEach(function (c) { select.appendChild(option(c.id, c.name + ' · ' + c.number)); });
              select.value = selected && $$('option', select).some(function (o) { return o.value === selected; }) ? selected : '';
              if (people.length === 1 && !selected) { select.value = people[0].id; }
              if (status) {
                var text = 'No matching client.';
                if (people.length === 1) { text = selected ? '1 client found.' : '1 client found and chosen.'; }
                if (people.length > 1) { text = people.length + ' clients found. Choose one below.'; }
                if (data.more) { text += ' More match: type more of the name.'; }
                status.textContent = text;
              }
            })
            .catch(function () { if (status && mine === seq) { status.textContent = 'Search is unavailable. Choose from the list.'; } });
        }, 250);
      });
    });
  });

  /* ---- New appointment: open the "Add a new client" disclosure from a link ---- */
  $$('[data-open-details]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      var details = doc.getElementById(link.getAttribute('data-open-details'));
      if (!details) { return; }
      event.preventDefault();
      details.open = true;
      details.scrollIntoView({ block: 'start' });
      var first = $('input[name="first_name"]', details);
      if (first) { first.focus({ preventScroll: true }); }
    });
  });

  /* ---- New appointment: carry the step-1 choices as they are NOW (not as the page rendered them) ---- */
  $$('form[data-carry-from]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var source = doc.getElementById(form.getAttribute('data-carry-from'));
      if (!source) { return; }
      $$('input[data-carry]', form).forEach(function (input) {
        var field = source.elements.namedItem(input.getAttribute('data-carry'));
        if (field && 'value' in field && !(field instanceof RadioNodeList)) { input.value = field.value; }
      });
    });
  });
})();
