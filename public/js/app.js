/*
 * WellNest UI behaviour. Vanilla, dependency-free, driven by data-* attributes (no inline handlers: the CSP
 * allows scripts from this origin only). Everything here is an enhancement: pages work without it.
 */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;

  function $(selector, context) { return (context || doc).querySelector(selector); }
  function $$(selector, context) { return Array.prototype.slice.call((context || doc).querySelectorAll(selector)); }
  function closest(node, selector) { return node && node.closest ? node.closest(selector) : null; }
  function prefersReducedMotion() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }

  /* -------------------------------------------------------------- sidebar */

  function initSidebar() {
    var shell = $('[data-app-shell]');
    if (!shell) { return; }
    var main = $('[data-app-main]');
    var sidebar = $('[data-sidebar]');
    var toggles = $$('[data-sidebar-toggle]');
    var small = window.matchMedia ? window.matchMedia('(max-width: 63.999rem)') : null;
    var returnFocus = null;

    function setOpen(open) {
      if (open === shell.hasAttribute('data-sidebar-open')) { return; }
      if (open) { returnFocus = doc.activeElement; shell.setAttribute('data-sidebar-open', ''); }
      else { shell.removeAttribute('data-sidebar-open'); }
      toggles.forEach(function (t) { t.setAttribute('aria-expanded', open ? 'true' : 'false'); });
      // While the off-canvas menu is open, the page behind it is not reachable by keyboard or screen reader.
      if (main) { main.inert = open && !!(small && small.matches); }
      if (open && sidebar) {
        var first = $('a[href], button', sidebar);
        if (first) { first.focus(); }
      } else if (returnFocus && returnFocus.focus) {
        returnFocus.focus();
        returnFocus = null;
      }
    }

    doc.addEventListener('click', function (event) {
      if (closest(event.target, '[data-sidebar-toggle]')) { setOpen(!shell.hasAttribute('data-sidebar-open')); }
      else if (closest(event.target, '[data-sidebar-close]')) { setOpen(false); }
    });
    doc.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && shell.hasAttribute('data-sidebar-open') && !$('details.dropdown[open]')) { setOpen(false); }
    });
    if (small && small.addEventListener) {
      small.addEventListener('change', function () { if (!small.matches) { setOpen(false); } });
    }
  }

  /* -------------------------------------------------------------- dropdowns */

  function openMenus() { return $$('details.dropdown[open]'); }
  function closeMenus(except) { openMenus().forEach(function (d) { if (d !== except) { d.open = false; } }); }

  // The menu is placed with fixed positioning so a table wrapper or card can never clip it. Its offset is
  // measured against whatever the real containing block is, so a transformed ancestor cannot throw it off.
  function placeMenu(details) {
    var menu = $('.dropdown__menu', details);
    var trigger = $('summary', details);
    if (!menu || !trigger) { return; }
    menu.style.position = 'fixed';
    menu.style.top = '0px';
    menu.style.left = '0px';
    menu.style.right = 'auto';
    menu.style.maxHeight = '';
    menu.style.overflowY = '';
    var anchor = trigger.getBoundingClientRect();
    menu.style.minWidth = Math.max(anchor.width, 192) + 'px';
    var origin = menu.getBoundingClientRect();
    var width = menu.offsetWidth;
    var height = menu.offsetHeight;
    var viewW = root.clientWidth;
    var viewH = root.clientHeight;
    var gap = 6;
    var margin = 8;
    var left = details.classList.contains('dropdown--right') ? anchor.right - width : anchor.left;
    left = Math.max(margin, Math.min(left, viewW - width - margin));
    var top = anchor.bottom + gap;
    if (top + height > viewH - margin) {
      if (anchor.top - gap - height >= margin) { top = anchor.top - gap - height; }
      else { menu.style.maxHeight = Math.max(160, viewH - top - margin) + 'px'; menu.style.overflowY = 'auto'; }
    }
    menu.style.left = (left - origin.left) + 'px';
    menu.style.top = (top - origin.top) + 'px';
  }

  function resetMenu(details) {
    var menu = $('.dropdown__menu', details);
    if (menu) { menu.removeAttribute('style'); }
  }

  function initDropdowns() {
    // "toggle" does not bubble, so it is caught in the capture phase.
    doc.addEventListener('toggle', function (event) {
      var details = event.target;
      if (!details || !details.matches || !details.matches('details.dropdown')) { return; }
      if (details.open) { closeMenus(details); placeMenu(details); } else { resetMenu(details); }
    }, true);

    doc.addEventListener('click', function (event) {
      openMenus().forEach(function (d) { if (!d.contains(event.target)) { d.open = false; } });
      var item = closest(event.target, '.dropdown__menu a, .dropdown__menu button');
      var details = item && closest(item, 'details.dropdown');
      if (details && !item.hasAttribute('data-keep-open')) {
        setTimeout(function () { details.open = false; }, 0);
      }
    });

    doc.addEventListener('focusin', function (event) {
      openMenus().forEach(function (d) { if (!d.contains(event.target)) { d.open = false; } });
    });

    window.addEventListener('resize', function () { closeMenus(); });
    window.addEventListener('scroll', function (event) {
      var node = event.target;
      if (openMenus().length && !(node && node.nodeType === 1 && closest(node, '.dropdown__menu'))) { closeMenus(); }
    }, true);

    doc.addEventListener('keydown', function (event) {
      var open = $('details.dropdown[open]');
      if (event.key === 'Escape' && open) {
        event.preventDefault(); // also keeps an enclosing <dialog> from closing with the menu
        var summary = $('summary', open);
        open.open = false;
        if (summary && open.contains(doc.activeElement)) { summary.focus(); }
        return;
      }
      var navKeys = ['ArrowDown', 'ArrowUp', 'Home', 'End'];
      if (navKeys.indexOf(event.key) === -1) { return; }
      var details = closest(event.target, 'details.dropdown');
      if (!details) { return; }
      var items = $$('a[href], button:not([disabled])', $('.dropdown__menu', details));
      if (!items.length) { return; }
      if (!details.open) {
        if (closest(event.target, 'summary') && event.key !== 'Home' && event.key !== 'End') {
          event.preventDefault();
          details.open = true;
          items[event.key === 'ArrowUp' ? items.length - 1 : 0].focus();
        }
        return;
      }
      event.preventDefault();
      var index = items.indexOf(doc.activeElement);
      var next;
      if (event.key === 'ArrowDown') { next = index < 0 ? 0 : (index + 1) % items.length; }
      else if (event.key === 'ArrowUp') { next = index < 0 ? items.length - 1 : (index - 1 + items.length) % items.length; }
      else { next = event.key === 'Home' ? 0 : items.length - 1; }
      items[next].focus();
    });
  }

  /* ---------------------------------------------------------------- dialogs */

  var openers = typeof WeakMap === 'function' ? new WeakMap() : null;
  var pressedOn = null;

  function openDialog(dialog, opener) {
    if (!dialog || dialog.open) { return; }
    closeMenus();
    if (openers) { openers.set(dialog, opener || doc.activeElement); }
    if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
    var wanted = $('[autofocus]', dialog) || $('[aria-invalid="true"]', dialog);
    if (!wanted) { wanted = $('.modal__body input:not([type="hidden"]), .modal__body textarea, .modal__body select', dialog); }
    if (wanted) { wanted.focus(); }
  }

  function initDialogs() {
    doc.addEventListener('mousedown', function (event) { pressedOn = event.target; });
    doc.addEventListener('click', function (event) {
      var target = event.target;
      var opener = closest(target, '[data-dialog-open]');
      if (opener) {
        event.preventDefault();
        openDialog(doc.getElementById(opener.getAttribute('data-dialog-open')), opener);
        return;
      }
      var closer = closest(target, '[data-dialog-close]');
      if (closer) {
        var dialog = closest(closer, 'dialog');
        if (dialog) { event.preventDefault(); dialog.close(); }
        return;
      }
      // A click on the backdrop lands on the <dialog> itself (its panel fills the rest).
      if (target && target.nodeName === 'DIALOG' && target.hasAttribute('data-dismissible') && pressedOn === target) { target.close(); }
    });

    // "close" does not bubble; return focus to whatever opened the dialog.
    doc.addEventListener('close', function (event) {
      var dialog = event.target;
      if (!dialog || dialog.nodeName !== 'DIALOG' || !openers) { return; }
      var opener = openers.get(dialog);
      openers.delete(dialog);
      if (!opener || !opener.isConnected || !opener.focus) { return; }
      var menu = closest(opener, 'details');
      if (menu && !menu.open) { var summary = $('summary', menu); if (summary) { summary.focus(); } }
      else { opener.focus(); }
    }, true);

    var auto = $('dialog[data-open-on-load]');
    if (auto) { openDialog(auto, null); }
  }

  /* ------------------------------------------------------- alerts and flash */

  function removeAlert(alert) {
    var region = alert.parentElement;
    alert.remove();
    if (region && region.hasAttribute('data-flash-region') && !region.children.length) { region.remove(); }
  }

  function dismissAlert(alert) {
    if (!alert) { return; }
    if (prefersReducedMotion()) { removeAlert(alert); return; }
    alert.classList.add('is-leaving');
    setTimeout(function () { removeAlert(alert); }, 220);
  }

  function initAlerts() {
    $$('[data-dismiss]').forEach(function (button) { button.hidden = false; });
    doc.addEventListener('click', function (event) {
      var button = closest(event.target, '[data-dismiss]');
      if (button) { dismissAlert(closest(button, '.alert')); }
    });
    // Confirmations tidy themselves away; hovering or focusing one holds it in place.
    $$('[data-autodismiss]').forEach(function (alert) {
      var delay = parseInt(alert.getAttribute('data-autodismiss'), 10) || 9000;
      var timer = null;
      function start() { clearTimeout(timer); timer = setTimeout(function () { dismissAlert(alert); }, delay); }
      function stop() { clearTimeout(timer); }
      alert.addEventListener('mouseenter', stop);
      alert.addEventListener('mouseleave', start);
      alert.addEventListener('focusin', stop);
      alert.addEventListener('focusout', start);
      start();
    });
  }

  /* ------------------------------------------------------------------ forms */

  var LOCK_MS = 30000;

  function releaseForm(form) {
    if (!form._locked) { return; }
    form._locked.forEach(function (b) { b.disabled = false; b.removeAttribute('aria-busy'); b.classList.remove('is-loading'); });
    form._locked = null;
    delete form.dataset.submitting;
  }

  function initForms() {
    // Capture phase, so it sees the event before any other handler has had a chance to cancel it.
    doc.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || !form.hasAttribute || !form.hasAttribute('data-submit-once')) { return; }
      if (form.dataset.submitting === '1') { event.preventDefault(); return; }
      form.dataset.submitting = '1';
      var submitter = event.submitter || null;
      setTimeout(function () {
        // Disabled only after the browser has built the form data, or the submitter's value would be lost.
        if (event.defaultPrevented) { delete form.dataset.submitting; return; }
        var buttons = Array.prototype.filter.call(form.elements, function (el) {
          return el.matches && el.matches('button[type="submit"], button:not([type]), input[type="submit"]') && !el.disabled;
        });
        form._locked = buttons;
        buttons.forEach(function (b) {
          b.disabled = true;
          b.setAttribute('aria-busy', 'true');
          if (b === submitter || (!submitter && b === buttons[0])) { b.classList.add('is-loading'); }
        });
        // A download (or a failed navigation) never replaces the page, so the lock must not last for ever.
        setTimeout(function () { releaseForm(form); }, LOCK_MS);
      }, 0);
    }, true);

    // Back/forward cache restores the page as it was left: unlock anything that was mid-submit.
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) { $$('form[data-submitting]').forEach(releaseForm); }
    });

    doc.addEventListener('change', function (event) {
      var el = event.target;
      if (el && el.matches && el.matches('[data-autosubmit]') && el.form) {
        if (typeof el.form.requestSubmit === 'function') { el.form.requestSubmit(); } else { el.form.submit(); }
      }
    });

    // Show / hide password. The buttons are rendered hidden and revealed here, so no dead control without JS.
    $$('[data-password-toggle]').forEach(function (button) { button.hidden = false; });
    doc.addEventListener('click', function (event) {
      var button = closest(event.target, '[data-password-toggle]');
      if (!button) { return; }
      var input = doc.getElementById(button.getAttribute('aria-controls'));
      if (!input) { return; }
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.setAttribute('aria-pressed', show ? 'true' : 'false');
      button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      var iconShow = $('[data-icon-show]', button);
      var iconHide = $('[data-icon-hide]', button);
      if (iconShow) { iconShow.hidden = show; }
      if (iconHide) { iconHide.hidden = !show; }
    });
    // Never leave a typed password readable in a restored page or a page snapshot.
    window.addEventListener('pagehide', function () {
      $$('[data-password-toggle][aria-pressed="true"]').forEach(function (b) { b.click(); });
    });

    // Colour input: the swatch and the hex box stay in step.
    $$('[data-color-input]').forEach(function (wrap) {
      var picker = $('[data-color-picker]', wrap);
      var text = $('[data-color-text]', wrap);
      if (!picker || !text) { return; }
      picker.hidden = false;
      function expand(v) { return /^#[0-9a-f]{3}$/i.test(v) ? '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3] : v; }
      picker.addEventListener('input', function () { text.value = picker.value; });
      text.addEventListener('input', function () {
        var v = expand(text.value.trim());
        if (/^#[0-9a-f]{6}$/i.test(v)) { picker.value = v.toLowerCase(); }
      });
    });

    // The first invalid field takes focus, so a keyboard or screen-reader user lands on the problem.
    if (!$('dialog[open]')) {
      var invalid = $('[aria-invalid="true"]');
      var active = doc.activeElement;
      var typing = active && active.matches && active.matches('input, select, textarea') && active !== invalid;
      if (invalid && !typing && invalid.offsetParent !== null) { invalid.focus(); }
    }
  }

  /* ------------------------------------------- copy, print, history, tables */

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text); }
    return new Promise(function (resolve, reject) {
      var area = doc.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      doc.body.appendChild(area);
      area.select();
      try { if (doc.execCommand('copy')) { resolve(); } else { reject(new Error('copy failed')); } }
      catch (e) { reject(e); }
      finally { area.remove(); }
    });
  }

  function initUtilities() {
    $$('[data-copy], [data-print], [data-history-back]').forEach(function (el) {
      if (el.hasAttribute('data-history-back') && window.history.length < 2) { return; }
      el.hidden = false;
    });

    doc.addEventListener('click', function (event) {
      var copy = closest(event.target, '[data-copy]');
      if (copy) {
        var source = $(copy.getAttribute('data-copy-target') || '');
        if (!source) { return; }
        var items = $$('li', source);
        var text = items.length ? items.map(function (li) { return li.textContent.trim(); }).join('\n') : source.textContent.trim();
        copyText(text).then(function () {
          var label = $('[data-copy-label]', copy);
          if (!label) { return; }
          var original = label.getAttribute('data-original') || label.textContent;
          label.setAttribute('data-original', original);
          label.setAttribute('aria-live', 'polite');
          label.textContent = label.getAttribute('data-copied-label') || 'Copied';
          setTimeout(function () { label.textContent = original; }, 2000);
        }, function () { /* clipboard unavailable: the text is still on screen to select */ });
        return;
      }
      var print = closest(event.target, '[data-print]');
      if (print) {
        var target = $(print.getAttribute('data-print-target') || '');
        if (target) {
          target.classList.add('is-print-focus');
          doc.body.classList.add('print-focus');
          window.addEventListener('afterprint', function done() {
            target.classList.remove('is-print-focus');
            doc.body.classList.remove('print-focus');
            window.removeEventListener('afterprint', done);
          });
        }
        window.print();
        return;
      }
      if (closest(event.target, '[data-history-back]')) { window.history.back(); }
    });

    // A horizontally scrolling table is a tab stop only while it actually overflows.
    var scrollers = $$('[data-table-scroll]');
    function sync(el) { if (el.scrollWidth > el.clientWidth + 1) { el.setAttribute('tabindex', '0'); } else { el.removeAttribute('tabindex'); } }
    scrollers.forEach(sync);
    if (typeof ResizeObserver === 'function') {
      var observer = new ResizeObserver(function (entries) { entries.forEach(function (en) { sync(closest(en.target, '[data-table-scroll]') || en.target); }); });
      scrollers.forEach(function (el) { observer.observe(el); if (el.firstElementChild) { observer.observe(el.firstElementChild); } });
    } else {
      window.addEventListener('resize', function () { scrollers.forEach(sync); });
    }

    // Sorted column headers expose aria-sort even when the link was written inside a plain <th>.
    $$('a[data-sort-state]').forEach(function (link) {
      var th = closest(link, 'th');
      var state = link.getAttribute('data-sort-state');
      if (th && state !== 'none' && !th.hasAttribute('aria-sort')) { th.setAttribute('aria-sort', state); }
    });

    // Server-side tabs scroll the current one into view on narrow screens.
    $$('[data-tabs]').forEach(function (nav) {
      var current = $('[aria-current]', nav);
      if (current && nav.scrollWidth > nav.clientWidth) { nav.scrollLeft = Math.max(0, current.offsetLeft - (nav.clientWidth - current.offsetWidth) / 2); }
    });
  }

  /* ----------------------------------------------------------- notifications */

  // All / Unread / Mentions filter inside the bell dropdown (items carry data-notif-unread / data-notif-mention).
  function initNotifications() {
    $$('[data-notifications]').forEach(function (menu) {
      var tabs = $$('[data-notif-tab]', menu);
      if (!tabs.length) { return; }
      tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          var kind = tab.getAttribute('data-notif-tab');
          tabs.forEach(function (t) { t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
          $$('[data-notif-unread]', menu).forEach(function (item) {
            var show = kind === 'all' || (kind === 'unread' && item.getAttribute('data-notif-unread') === '1') || (kind === 'mention' && item.getAttribute('data-notif-mention') === '1');
            item.hidden = !show;
          });
        });
      });
    });
  }

  /* ------------------------------------------------ inside a call page's app frame */

  // While a video call floats, the telehealth call page shows our pages in its app frame (public/js/screens/
  // telehealth.js). Inside that frame, whatever leaves this organization's app (sign-out, account, the platform
  // console, another organization, another site) replaces the whole tab: those pages refuse to be framed. Signing
  // out also tells the call page not to ask "Leave the call?". Inactive in any other page.
  function initCallFrame() {
    var host = null;
    if (window.top === window) { return; }
    try { host = window.parent.document.querySelector('[data-call-host]'); } catch (e) { return; }
    if (!host) { return; }
    var base = host.getAttribute('data-call-host') || '';
    function leavesApp(raw) {
      var url;
      try { url = new URL(raw, window.location.href); } catch (e) { return false; }
      if (url.protocol !== 'http:' && url.protocol !== 'https:') { return false; }
      return url.origin !== window.location.origin || (url.pathname !== base && url.pathname.indexOf(base + '/') !== 0);
    }
    doc.addEventListener('click', function (event) {
      var link = closest(event.target, 'a[href]');
      if (link && !link.hasAttribute('target') && !link.hasAttribute('download') && leavesApp(link.getAttribute('href'))) { link.setAttribute('target', '_top'); }
    }, true);
    doc.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || form.nodeName !== 'FORM' || form.hasAttribute('target') || !leavesApp(form.getAttribute('action') || window.location.href)) { return; }
      form.setAttribute('target', '_top');
      if ((form.getAttribute('method') || 'get').toLowerCase() === 'post') { host.setAttribute('data-call-leaving', ''); }
    }, true);
  }

  /* ------------------------------------------------------------------- boot */

  function boot() {
    initSidebar();
    initDropdowns();
    initNotifications();
    initDialogs();
    initAlerts();
    initForms();
    initUtilities();
    initCallFrame();
  }

  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', boot); } else { boot(); }
}());
