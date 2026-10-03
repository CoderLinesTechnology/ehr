/*
 * The telehealth call page only (spec docs/design/spec/screens/12-telehealth-call.md): the call host — full screen,
 * and the call floating while the user browses the app in the app frame — plus the "call already open" stub.
 * Split from telehealth.js so the join page and the device check do not load it. No dependencies and no network.
 * The call itself is Daily Prebuilt in an iframe; this script never moves, reloads or scripts it.
 */
(function () {
  'use strict';

  function $(root, sel) { return root.querySelector(sel); }

  /* ------------------------------------------------------------------------------------------- the call host */
  /*
   * With a call, the call page is a "call host" (spec docs/design/spec/screens/12-telehealth-call.md):
   *  - Links and GET forms of this page that lead into this organization's app open in the app frame
   *    (name "wellnest-app"); the call then floats in a small window above it and the page around the frame is
   *    inert. Native navigation does the rest: the pages in the frame are ordinary pages with their own scripts.
   *  - The Daily frame NEVER moves in the DOM (a moved iframe reloads and the call drops): its dock stays in the
   *    stage and only classes and inline geometry change (docked, floating, full screen).
   *  - Every app-frame load is checked: this session's join or call page brings the call back; a page outside this
   *    organization's app replaces the whole tab (the call ends, as on sign-out); a page that cannot be read (it
   *    refuses frames, or the network failed) is settled by asking the server whether we are still signed in.
   *  - The frame's page is mirrored into ?app= (history.replaceState), so a refresh restores it (validated on
   *    the server before it is printed back).
   *  - Leaving while the call runs asks first, except for Leave call and this page's own forms (Complete session, consent, sign out)
   *    and sign-out inside the frame (public/js/app.js marks it).
   */
  var APP_FRAME = 'wellnest-app';
  var CORNERS = [[1, 1], [0, 1], [0, 0], [1, 0]];
  var CORNER_NAMES = ['bottom right', 'bottom left', 'top left', 'top right'];
  var POSITION_KEY = 'wellnest:call-window';

  /** The call host element of the page around this one (same origin only), or null. */
  function hostAbove() {
    if (window.parent === window) { return null; }
    try { return window.parent.document.querySelector('[data-call-host]'); } catch (e) { return null; }
  }

  function tellHost(outer, callPath) {
    outer.dispatchEvent(new window.parent.CustomEvent('wellnest:call-open', { detail: callPath }));
  }

  function clamp(n) { return Math.min(1, Math.max(0, n)); }
  function isUnit(n) { return typeof n === 'number' && n >= 0 && n <= 1; }

  function initCallHost(host) {
    var dock = $(host, '[data-call-dock]');
    var frame = document.querySelector('[data-call-app-frame]');
    var area = document.querySelector('[data-call-area]');
    if (!dock || !frame || !area) { return; }
    var bar = $(dock, '[data-call-bar]');
    var status = $(dock, '[data-call-status]');
    var heading = document.getElementById('tv-title');
    var base = host.getAttribute('data-call-host');
    var callPath = host.getAttribute('data-call-path');
    var joinPath = host.getAttribute('data-call-join');
    var hostTitle = document.title;
    var floating = false;
    var guard = true;
    var lastPath = null;      // the app page last shown in the frame (path + query)
    var frameTitle = '';
    var recovering = false;
    var inerted = [];
    var position = readPosition();

    function inApp(url) {
      return url.origin === window.location.origin && (url.pathname === base || url.pathname.indexOf(base + '/') === 0);
    }
    function isThisCall(url) { return url.pathname === callPath || url.pathname === joinPath; }

    function say(text) {
      status.textContent = '';
      setTimeout(function () { status.textContent = text; }, 60);
    }

    /* Position: a fraction of the free space inside the float area, so it survives resizes and refreshes. */
    function readPosition() {
      try {
        var saved = JSON.parse(window.sessionStorage.getItem(POSITION_KEY) || 'null');
        if (saved && isUnit(saved.x) && isUnit(saved.y)) { return { x: saved.x, y: saved.y }; }
      } catch (e) { /* storage blocked or unreadable: the default corner */ }
      return { x: 1, y: 1 };
    }
    function savePosition() {
      try { window.sessionStorage.setItem(POSITION_KEY, JSON.stringify(position)); } catch (e) { /* not remembered */ }
    }
    function bounds() {
      var r = area.getBoundingClientRect();
      return { left: r.left, top: r.top, width: Math.max(0, r.width - dock.offsetWidth), height: Math.max(0, r.height - dock.offsetHeight) };
    }
    function place() {
      var b = bounds();
      dock.style.transform = 'translate(' + Math.round(b.left + position.x * b.width) + 'px, ' + Math.round(b.top + position.y * b.height) + 'px)';
    }

    /* While the call floats, everything but the call window and the app frame is inert (keyboard, pointer, screen reader). */
    function keeps(node) { return node.contains(dock) || node.contains(frame); }
    function setInert(on) {
      inerted.forEach(function (el) { el.inert = false; });
      inerted = [];
      if (!on) { return; }
      [dock, frame].forEach(function (node) {
        for (var el = node; el !== document.body && el.parentElement; el = el.parentElement) {
          Array.prototype.forEach.call(el.parentElement.children, function (sibling) {
            if (sibling.inert || keeps(sibling) || /^(SCRIPT|STYLE|LINK|TEMPLATE)$/.test(sibling.nodeName)) { return; }
            sibling.inert = true;
            inerted.push(sibling);
          });
        }
      });
    }

    function mirror() {
      var url = new URL(window.location.href);
      if (floating && lastPath) { url.searchParams.set('app', lastPath); } else { url.searchParams.delete('app'); }
      if (url.href !== window.location.href) { window.history.replaceState(window.history.state, '', url.href); }
    }

    function setMode(next, quiet) {
      if (next === floating) { return; }
      floating = next;
      document.documentElement.classList.toggle('tv-floating', floating);
      dock.classList.toggle('is-floating', floating);
      frame.hidden = !floating;
      setInert(floating);
      if (floating) { place(); } else { dock.style.transform = ''; }
      document.title = floating && frameTitle ? frameTitle : hostTitle;
      mirror();
      if (!quiet) { say(floating ? 'Call minimized — still connected' : 'Call expanded'); }
    }

    function expand() {
      if (!floating) { return; }
      setMode(false);
      if (heading) { heading.focus(); }
    }

    function blank() {
      lastPath = null;
      frameTitle = '';
      try { frame.contentWindow.location.replace('about:blank'); } catch (e) { frame.setAttribute('src', 'about:blank'); }
      mirror();
    }

    function closeSidebar() {
      var shell = document.querySelector('[data-app-shell][data-sidebar-open]');
      var close = shell && shell.querySelector('[data-sidebar-close]');
      if (close) { close.click(); }
    }

    function openInFrame() {
      closeSidebar();
      setMode(true);
      frame.focus();
    }

    function leaveTo(url) {
      guard = false;
      window.location.assign(url);
    }

    function allowLeaving() {
      guard = false;
      setTimeout(function () { guard = true; }, 3000);   // only if the page is somehow still here
    }

    /* Links and GET forms into the app open in the frame. Not: this call's own pages (its "Back to session" link
       leaves the call as before, after asking), new-tab/download/fragment links, POST forms, anything outside the app. */
    function retarget() {
      Array.prototype.forEach.call(document.querySelectorAll('a[href]'), function (link) {
        var href = link.getAttribute('href');
        if (!href || href.charAt(0) === '#' || link.hasAttribute('target') || link.hasAttribute('download') || dock.contains(link)) { return; }
        var url;
        try { url = new URL(link.href); } catch (e) { return; }
        if (inApp(url) && !isThisCall(url)) { link.setAttribute('target', APP_FRAME); }
      });
      Array.prototype.forEach.call(document.forms, function (form) {
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get' || form.hasAttribute('target')) { return; }
        var url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        if (inApp(url) && !isThisCall(url)) { form.setAttribute('target', APP_FRAME); }
      });
    }

    document.addEventListener('click', function (event) {
      var link = event.target.closest ? event.target.closest('a[target="' + APP_FRAME + '"]') : null;
      if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
      openInFrame();
    });
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (event.defaultPrevented || !form || form.nodeName !== 'FORM') { return; }
      if (form.getAttribute('target') === APP_FRAME) { openInFrame(); return; }
      allowLeaving();   // this page's own forms (Complete session, consent, sign out) leave on purpose
    });

    /* Every page the frame lands on. */
    function recover() {
      say('That page could not be opened during the call.');
      if (lastPath && !recovering) {
        recovering = true;
        try { frame.contentWindow.location.replace(lastPath); } catch (e) { frame.setAttribute('src', lastPath); }
        return;
      }
      recovering = false;
      expand();
      blank();
    }
    function unreadable() {
      // A page we cannot read: one that refuses frames (sign-in after the session expired, account, platform) or a
      // network error. Signed out → the whole tab goes through the server (to sign-in); otherwise the call stays.
      window.fetch(base, { method: 'HEAD', credentials: 'same-origin', redirect: 'manual', cache: 'no-store' }).then(function (response) {
        if (response.type === 'opaqueredirect') { leaveTo(window.location.href); return; }
        recover();
      }, recover);
    }
    frame.addEventListener('load', function () {
      var win = frame.contentWindow;
      var href = null;
      try { href = win.location.href; } catch (e) { href = null; }
      if (href === 'about:blank') { return; }
      if (href === null) { unreadable(); return; }
      var url = new URL(href);
      if (isThisCall(url)) { expand(); blank(); return; }
      if (!inApp(url)) { leaveTo(url.href); return; }
      recovering = false;
      lastPath = url.pathname + url.search;
      try { frameTitle = win.document.title || ''; } catch (e) { frameTitle = ''; }
      frame.title = frameTitle || 'WellNest';
      if (floating) {
        document.title = frameTitle || hostTitle;
        mirror();
        if (!document.activeElement || document.activeElement === document.body) { frame.focus(); }
      }
    });
    // The call page opened inside the frame (a stub) asks for its call back.
    host.addEventListener('wellnest:call-open', function (event) {
      if (event.detail === callPath) { expand(); blank(); }
    });

    /* The window bar. */
    $(dock, '[data-call-expand]').addEventListener('click', expand);

    $(dock, '[data-call-move]').addEventListener('click', function () {
      var current = 0;
      CORNERS.forEach(function (corner, i) { if (Math.round(position.x) === corner[0] && Math.round(position.y) === corner[1]) { current = i; } });
      var next = (current + 1) % CORNERS.length;
      position = { x: CORNERS[next][0], y: CORNERS[next][1] };
      dock.classList.add('is-gliding');
      place();
      setTimeout(function () { dock.classList.remove('is-gliding'); }, 260);
      savePosition();
      say('Call window moved to the ' + CORNER_NAMES[next] + ' corner');
    });

    bar.addEventListener('pointerdown', function (event) {
      if (!floating || document.fullscreenElement || event.button !== 0 || (event.target.closest && event.target.closest('button'))) { return; }
      event.preventDefault();
      var rect = dock.getBoundingClientRect();
      var offsetX = event.clientX - rect.left;
      var offsetY = event.clientY - rect.top;
      bar.setPointerCapture(event.pointerId);
      document.documentElement.classList.add('tv-dragging');
      function move(e) {
        var b = bounds();
        position = { x: b.width ? clamp((e.clientX - offsetX - b.left) / b.width) : 0, y: b.height ? clamp((e.clientY - offsetY - b.top) / b.height) : 0 };
        place();
      }
      function stop() {
        bar.removeEventListener('pointermove', move);
        bar.removeEventListener('pointerup', stop);
        bar.removeEventListener('pointercancel', stop);
        document.documentElement.classList.remove('tv-dragging');
        savePosition();
      }
      bar.addEventListener('pointermove', move);
      bar.addEventListener('pointerup', stop);
      bar.addEventListener('pointercancel', stop);
    });

    // Leave call: disconnect without finishing the session (it stays in progress: rejoin or complete it from the join
    // page). From the floating window the user stays on the page they are viewing; from the call view they go to the
    // join page. No "Leave the call?" prompt: this is the explicit way out.
    Array.prototype.forEach.call(document.querySelectorAll('[data-call-leave]'), function (control) {
      control.addEventListener('click', function (event) {
        event.preventDefault();
        leaveTo(floating && lastPath ? lastPath : joinPath);
      });
    });

    /* Full screen: the dock (never the iframe itself, never moved). Daily's own control works too (allowfullscreen). */
    var fullscreenButtons = Array.prototype.slice.call(document.querySelectorAll('[data-call-fullscreen]'));
    if (document.fullscreenEnabled && dock.requestFullscreen) {
      fullscreenButtons.forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
          if (document.fullscreenElement) { document.exitFullscreen().catch(function () { /* already left */ }); return; }
          dock.requestFullscreen().catch(function () { say('Full screen is not available here.'); });
        });
      });
      document.addEventListener('fullscreenchange', function () {
        var on = document.fullscreenElement === dock;
        fullscreenButtons.forEach(function (button) {
          button.setAttribute('aria-pressed', on ? 'true' : 'false');
          button.title = on ? 'Exit full screen (Esc)' : 'Full screen';
        });
        var barButton = $(bar, '[data-call-fullscreen]');
        if (on && !dock.contains(document.activeElement)) { barButton.focus(); }
        if (!on && !floating && bar.contains(document.activeElement)) { fullscreenButtons[0].focus(); }
        // Leaving full screen, the dock still has its full-screen size during this event: place it once it has shrunk.
        if (!on && floating) { requestAnimationFrame(function () { requestAnimationFrame(place); }); }
      });
    }

    window.addEventListener('resize', function () { if (floating) { place(); } });
    // The window is placed from its own size. When that size settles — leaving full screen can take a few frames, so
    // the requestAnimationFrame above sometimes still sees the full-screen size and puts it at the left edge — place it
    // again. Moving it (a transform) never changes the observed size, so this cannot loop.
    if (window.ResizeObserver) {
      new ResizeObserver(function () { if (floating && document.fullscreenElement !== dock) { place(); } }).observe(dock);
    }

    window.addEventListener('beforeunload', function (event) {
      if (!guard || host.hasAttribute('data-call-leaving')) { return undefined; }
      event.preventDefault();
      event.returnValue = 'Leave the call?';
      return 'Leave the call?';
    });

    retarget();

    // A refresh while browsing: the server validated ?app= (data-call-app); reopen it with the call floating.
    var restore = host.getAttribute('data-call-app');
    if (restore && inApp(new URL(restore, window.location.href))) {
      lastPath = restore;
      frame.setAttribute('src', restore);
      setMode(true, true);
    }
  }

  var callHost = document.querySelector('[data-call-host]');
  var outerHost = hostAbove();
  if (callHost && outerHost) {
    // This call page was opened inside another call page's app frame by a browser that sends no Sec-Fetch-Dest
    // (the server would have sent the stub): never a second connection.
    var nested = $(callHost, '.tv-frame');
    if (nested) { nested.remove(); }
    tellHost(outerHost, callHost.getAttribute('data-call-path'));
  } else if (callHost) {
    initCallHost(callHost);
  }

  // The stub the server sends when the call page is requested inside a frame: bring that call back, or explain.
  var stub = document.querySelector('[data-call-open]');
  if (stub && outerHost) {
    if (outerHost.getAttribute('data-call-path') === stub.getAttribute('data-call-open')) {
      tellHost(outerHost, stub.getAttribute('data-call-open'));
    } else {
      $(stub, '[data-call-open-same]').hidden = true;
      $(stub, '[data-call-open-other]').hidden = false;
    }
  }
}());
