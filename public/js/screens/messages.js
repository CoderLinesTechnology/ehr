/* Messages screen: polling, send/react/retract without a reload, read markers, small composer helpers.
   No inline script (CSP script-src 'self'); same-origin fetch only (connect-src 'self'). Everything also works as plain forms without it. */
(function () {
  'use strict';
  var root = document.querySelector('[data-msgs]');
  if (!root) { return; }

  var POLL_MS = 10000, READ_DWELL_MS = 2500;
  var flow = root.querySelector('[data-flow]');
  var scroller = root.querySelector('[data-scroll]');
  var composer = root.querySelector('[data-composer]');
  var pollUrl = root.getAttribute('data-poll-url');
  var readUrl = root.getAttribute('data-read-url');
  var listUrl = root.getAttribute('data-list-url');
  var signature = root.getAttribute('data-signature') || '';
  var token = (composer && composer.querySelector('input[name="_token"]') || {}).value || '';
  var busy = false;

  function $(sel, ctx) { return (ctx || root).querySelector(sel); }
  function visible() { return document.visibilityState === 'visible'; }
  function bottom() { if (scroller) { scroller.scrollTop = scroller.scrollHeight; } }
  function nearBottom() { return !scroller || scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 120; }

  function request(url, options) {
    options = options || {};
    options.credentials = 'same-origin';
    options.headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, options.headers || {});
    if (options.method === 'POST') { options.headers['X-CSRF-TOKEN'] = token; }
    return fetch(url, options).then(function (r) {
      if (!r.ok) { return r.json().catch(function () { return {}; }).then(function (b) { throw Object.assign(new Error(b.message || 'Request failed'), { status: r.status, body: b }); }); }
      return r.status === 204 ? {} : r.json();
    });
  }

  function htmlToNodes(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content; }

  /* ---- unread counts: tabs, nav badge, row badges ---- */
  function applyUnread(u) {
    if (!u) { return; }
    var map = { all: u.total, clients: (u.byKind || {}).client || 0 };
    Object.keys(map).forEach(function (key) {
      var tab = $('.msgs-tab[data-tab="' + key + '"]');
      if (!tab) { return; }
      var chip = $('.msgs-tab__count', tab);
      if (map[key] > 0) {
        if (!chip) { chip = document.createElement('span'); chip.className = 'msgs-tab__count'; chip.setAttribute('data-count', key); tab.appendChild(chip); }
        chip.textContent = map[key];
      } else if (chip) { chip.remove(); }
    });
    var link = document.querySelector('.nav-link[href$="/messages"]');
    if (link) {
      var badge = link.querySelector('.nav-badge');
      if (u.total > 0) {
        if (!badge) { badge = document.createElement('span'); badge.className = 'nav-badge'; link.appendChild(badge); }
        badge.textContent = u.total > 99 ? '99+' : u.total;
      } else if (badge) { badge.remove(); }
    }
  }

  function refreshList() {
    var params = new URLSearchParams(window.location.search);
    var tab = $('.msgs-tab.is-active');
    var search = $('.msgs-search input[name="q"]');
    var q = new URLSearchParams();
    q.set('partial', 'list');
    if (tab && tab.getAttribute('data-tab') !== 'all') { q.set('tab', tab.getAttribute('data-tab')); }
    if (search && search.value) { q.set('q', search.value); }
    if (params.get('unread')) { q.set('unread', '1'); }
    return fetch(listUrl + '?' + q.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) {
        if (!html) { return; }
        var doc = htmlToNodes(html);
        ['tabs', 'rows'].forEach(function (part) {
          var fresh = doc.querySelector('[data-part="' + part + '"]'), old = $('[data-part="' + part + '"]');
          if (fresh && old) { old.innerHTML = fresh.innerHTML; }
        });
        var openId = (pollUrl || '').split('/messages/')[1];
        openId = openId ? openId.split('/')[0] : null;
        var row = openId && $('.msg-row[data-conversation="' + openId + '"]');
        if (row) { row.classList.add('is-selected'); row.setAttribute('aria-current', 'page'); }
      });
  }

  /* ---- messages: append, replace, ticks ---- */
  function applyReadUntil(iso) {
    if (!iso || !flow) { return; }
    flow.setAttribute('data-read-until', iso);
    flow.querySelectorAll('.msg[data-mine]').forEach(function (m) {
      if (m.getAttribute('data-at') <= iso) {
        var t = $('[data-ticks]', m);
        if (t && !t.classList.contains('is-read')) { t.classList.add('is-read'); t.title = 'Read'; var l = $('[data-ticks-label]', t); if (l) { l.textContent = 'Read'; } }
      }
    });
  }

  function poll() {
    if (!pollUrl || busy || !visible()) { return Promise.resolve(); }
    busy = true;
    var after = flow ? flow.getAttribute('data-latest') || '' : '';
    return request(pollUrl + '?after=' + encodeURIComponent(after) + '&sig=' + encodeURIComponent(signature))
      .then(function (d) {
        var stick = nearBottom();
        if (d.html && flow) {
          flow.appendChild(htmlToNodes(d.html));
          var empty = $('[data-empty]'); if (empty) { empty.remove(); }
          if (stick) { bottom(); }
        }
        if (d.latest && flow) { flow.setAttribute('data-latest', d.latest); }
        applyReadUntil(d.readUntil);
        applyUnread(d.unread);
        if (d.listChanged) { signature = d.unread.signature; return refreshList(); }
        if (d.incoming) { scheduleRead(0); }
      })
      .catch(function () { /* offline or signed out: try again next tick */ })
      .then(function () { busy = false; });
  }

  /* ---- read marker ---- */
  var readTimer = null;
  function scheduleRead(delay) {
    if (!readUrl) { return; }
    clearTimeout(readTimer);
    readTimer = setTimeout(function () {
      if (!visible()) { return; }
      request(readUrl, { method: 'POST' }).then(function (d) {
        applyUnread(d.unread);
        var open = $('.msg-row.is-selected .msg-badge'); if (open) { open.remove(); }
        signature = d.unread ? d.unread.signature : signature;
      }).catch(function () {});
    }, delay);
  }

  /* ---- in-thread actions: react, retract, load earlier ---- */
  root.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.matches('[data-react], [data-retract]')) {
      e.preventDefault();
      var article = form.closest('.msg');
      request(form.action, { method: 'POST', body: new FormData(form, e.submitter || null) })
        .then(function (d) { if (article && d.html) { article.replaceWith(htmlToNodes(d.html)); } })
        .catch(function (err) { window.alert(err.message); });
    }
  });

  root.addEventListener('click', function (e) {
    var more = e.target.closest('[data-earlier]');
    if (!more) { return; }
    e.preventDefault();
    var url = new URL(more.href, window.location.href);
    url.searchParams.set('partial', 'earlier');
    request(url.toString()).then(function (d) {
      var before = scroller.scrollHeight;
      flow.insertBefore(htmlToNodes(d.html), flow.firstChild);
      scroller.scrollTop += scroller.scrollHeight - before;
      if (d.earlier) { more.href = more.href.replace(/before=[^&]*/, 'before=' + encodeURIComponent(d.earlier)); more.setAttribute('data-earlier', d.earlier); } else { more.parentNode.remove(); }
    }).catch(function () { window.location.href = more.href; });
  });

  /* ---- composer ---- */
  if (composer) {
    var body = $('[data-body]', composer), file = $('[data-attachment]', composer), fileLabel = $('[data-file]', composer);
    var errorBox = $('[data-composer-error]', composer), sendBtn = $('.composer__send', composer);

    function grow() { body.style.height = 'auto'; body.style.height = Math.min(body.scrollHeight, 140) + 'px'; }
    function fail(msg) { errorBox.textContent = msg; errorBox.hidden = !msg; }

    body.addEventListener('input', grow);
    body.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); composer.requestSubmit(); }
    });
    file.addEventListener('change', function () {
      fail('');
      var f = file.files[0];
      if (f && f.size > 10 * 1024 * 1024) { fail('Attachments can be up to 10 MB.'); file.value = ''; f = null; }
      fileLabel.textContent = f ? f.name : '';
      fileLabel.hidden = !f;
    });
    composer.addEventListener('click', function (e) {
      var emoji = e.target.closest('[data-emoji]');
      if (!emoji) { return; }
      var s = body.selectionStart || body.value.length;
      body.value = body.value.slice(0, s) + emoji.getAttribute('data-emoji') + body.value.slice(body.selectionEnd || s);
      body.focus(); grow();
      emoji.closest('details').open = false;
    });
    composer.addEventListener('submit', function (e) {
      if (!window.fetch || !window.FormData) { return; }
      e.preventDefault();
      if (!body.value.trim() && !file.files.length) { return; }
      fail('');
      sendBtn.disabled = true;
      request(composer.action, { method: 'POST', body: new FormData(composer) })
        .then(function () {
          body.value = ''; file.value = ''; fileLabel.hidden = true; grow();
          busy = false; return poll().then(bottom);
        })
        .catch(function (err) {
          var errors = err.body && err.body.errors;
          fail(errors ? Object.keys(errors).map(function (k) { return errors[k][0]; })[0] : err.message);
        })
        .then(function () { sendBtn.disabled = false; composer.removeAttribute('data-submitting'); body.focus(); });
    });
    grow();
  }

  /* ---- list: live search ---- */
  var search = $('.msgs-search input[name="q"]');
  if (search) {
    var timer = null;
    search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(refreshList, 250); });
    search.form.addEventListener('submit', function (e) { e.preventDefault(); refreshList(); });
  }

  /* ---- new conversation: show the fields that fit the chosen type ---- */
  var drawerForm = document.querySelector('[data-new-conversation]');
  if (drawerForm) {
    var sync = function () {
      var checked = drawerForm.querySelector('[data-kind]:checked');
      var kind = checked ? checked.value : 'direct';
      drawerForm.querySelectorAll('[data-for-kind]').forEach(function (el) { el.hidden = el.getAttribute('data-for-kind').split(' ').indexOf(kind) === -1; });
    };
    drawerForm.addEventListener('change', function (e) {
      if (e.target.matches('[data-kind]')) { sync(); }
      // A direct conversation has exactly one other person.
      var kind = (drawerForm.querySelector('[data-kind]:checked') || {}).value;
      if (kind === 'direct' && e.target.matches('input[name="participants[]"]') && e.target.checked) {
        drawerForm.querySelectorAll('input[name="participants[]"]').forEach(function (c) { if (c !== e.target) { c.checked = false; } });
      }
    });
    sync();
  }

  /* ---- start up ---- */
  if (flow) {
    bottom();
    var first = flow.querySelector('.msg--in');
    if (first || (root.querySelector('.msg-row.is-selected .msg-badge'))) { scheduleRead(READ_DWELL_MS); }
    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', function () { if (visible()) { poll(); scheduleRead(1000); } });
  } else if (listUrl) {
    // The bare list: keep tab counts and rows fresh.
    setInterval(function () { if (visible()) { refreshList(); } }, POLL_MS * 3);
  }
})();
