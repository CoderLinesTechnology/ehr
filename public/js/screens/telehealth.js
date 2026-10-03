/*
 * Telehealth screens. No dependencies and no network.
 *
 *  - Device preview (join page and "Test Connection"): shows YOUR camera in this tab and a microphone level.
 *    Nothing is uploaded, recorded or stored; the tracks are stopped when you leave or press Join, so the
 *    call page's video frame gets the camera.
 *  - Copy Meeting Link (join and call pages): copies the client's link to the clipboard.
 *  The call page's host behaviour (floating call, full screen) lives in telehealth-call.js, loaded by that page only.
 *  The call itself is Daily Prebuilt in an iframe; this script never moves, reloads or scripts it.
 */
(function () {
  'use strict';

  var stream = null;
  var audioCtx = null;
  var raf = 0;

  function $(root, sel) { return root.querySelector(sel); }

  function stopAll() {
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    if (audioCtx) { try { audioCtx.close(); } catch (e) { /* closed already */ } audioCtx = null; }
  }

  function initPreview(root) {
    var video = $(root, 'video');
    var stage = $(root, '.tj-preview__stage');
    var status = $(root, '[data-status]');
    var chips = {
      camera: $(root, '[data-chip="camera"]'),
      microphone: $(root, '[data-chip="microphone"]'),
      settings: $(root, '[data-chip="settings"]')
    };
    var panel = $(root, '.tj-devices');
    var camSelect = $(root, '[data-device="videoinput"]');
    var micSelect = $(root, '[data-device="audioinput"]');

    function say(text) { status.textContent = text || ''; }
    function mark(chip, state) {
      chip.classList.toggle('is-ok', state === 'ok');
      chip.classList.toggle('is-bad', state === 'bad');
    }

    function meter(mediaStream) {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx || !mediaStream.getAudioTracks().length) { return; }
      audioCtx = new Ctx();
      var analyser = audioCtx.createAnalyser();
      analyser.fftSize = 256;
      audioCtx.createMediaStreamSource(mediaStream).connect(analyser);
      var data = new Uint8Array(analyser.frequencyBinCount);
      var level = $(chips.microphone, '[data-level]');
      level.hidden = false;
      (function tick() {
        analyser.getByteTimeDomainData(data);
        var peak = 0;
        for (var i = 0; i < data.length; i++) { peak = Math.max(peak, Math.abs(data[i] - 128)); }
        level.style.setProperty('--level', Math.min(1, peak / 64).toFixed(2));
        raf = requestAnimationFrame(tick);
      }());
    }

    function fillDevices() {
      if (!navigator.mediaDevices.enumerateDevices) { return; }
      navigator.mediaDevices.enumerateDevices().then(function (list) {
        [[camSelect, 'videoinput', 'Camera'], [micSelect, 'audioinput', 'Microphone']].forEach(function (pair) {
          var select = pair[0];
          var current = select.value;
          select.textContent = '';
          list.filter(function (d) { return d.kind === pair[1]; }).forEach(function (d, i) {
            var option = document.createElement('option');
            option.value = d.deviceId;
            option.textContent = d.label || (pair[2] + ' ' + (i + 1));
            select.appendChild(option);
          });
          if (current) { select.value = current; }
        });
      });
    }

    // Camera / Microphone: on until switched off here. Before the first test the chips run the test; once the preview
    // runs they switch that device off and on (aria-pressed), and the join form carries the choice into the call.
    var on = { camera: true, microphone: true };
    var tested = false;
    var generation = 0;   // each start() supersedes the ones still waiting for the browser

    function reflect(kind) {
      var chip = chips[kind];
      chip.classList.toggle('is-off', !on[kind]);
      if (tested) {
        chip.setAttribute('aria-pressed', on[kind] ? 'true' : 'false');
        chip.setAttribute('aria-label', kind === 'camera' ? 'Camera' : 'Microphone');
        chip.setAttribute('title', (kind === 'camera' ? 'Camera ' : 'Microphone ') + (on[kind] ? 'on — press to switch it off' : 'off — press to switch it on'));
      }
      var input = document.querySelector('[data-telehealth-join] [data-device-choice="' + kind + '"]');
      if (input) { input.value = on[kind] ? '1' : '0'; }
    }

    function offMessage() {
      if (!on.camera && !on.microphone) { return 'Camera and microphone are off. You will join with both off.'; }
      return !on.camera ? 'Your camera is off. You will join with it off.' : 'Your microphone is off. You will join muted.';
    }

    function start() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        say('This browser cannot access a camera or microphone here.');
        mark(chips.camera, 'bad'); mark(chips.microphone, 'bad');
        return;
      }
      stopAll();
      var mine = ++generation;
      video.hidden = true;
      stage.classList.remove('is-live');
      $(chips.microphone, '[data-level]').hidden = true;
      if (!on.camera && !on.microphone) {
        mark(chips.camera, null); mark(chips.microphone, null);
        say(offMessage());
        return;
      }
      say('Asking your browser for the ' + (on.camera && on.microphone ? 'camera and microphone' : (on.camera ? 'camera' : 'microphone')) + '…');
      var constraints = {
        video: on.camera ? (camSelect.value ? { deviceId: { exact: camSelect.value } } : true) : false,
        audio: on.microphone ? (micSelect.value ? { deviceId: { exact: micSelect.value } } : true) : false
      };
      navigator.mediaDevices.getUserMedia(constraints).then(function (s) {
        // A later start (automatic preview and a press, or quick presses) took over: never leave this device running.
        if (mine !== generation) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
        stream = s;
        tested = true;
        reflect('camera'); reflect('microphone');
        var hasVideo = s.getVideoTracks().length > 0;
        var hasAudio = s.getAudioTracks().length > 0;
        if (hasVideo) {
          video.srcObject = s;
          video.hidden = false;
          stage.classList.add('is-live');
          video.play().catch(function () { /* autoplay refused: the poster stays */ });
        }
        mark(chips.camera, on.camera ? (hasVideo ? 'ok' : 'bad') : null);
        mark(chips.microphone, on.microphone ? (hasAudio ? 'ok' : 'bad') : null);
        if (on.camera && on.microphone) {
          say(hasVideo && hasAudio ? 'Your camera and microphone work. Only you can see this preview.' : 'Part of your setup was not found.');
        } else {
          say(((on.camera && hasVideo) || (on.microphone && hasAudio) ? '' : 'Part of your setup was not found. ') + offMessage());
        }
        if (hasAudio) { meter(s); }
        fillDevices();
      }).catch(function (e) {
        if (mine !== generation) { return; }
        mark(chips.camera, 'bad'); mark(chips.microphone, 'bad');
        say(e && e.name === 'NotAllowedError'
          ? 'Your browser blocked the camera or microphone. Allow them from the address bar, then press Camera again.'
          : 'No camera or microphone was found, or another app is using it.');
      });
    }

    function toggle(kind) {
      if (!tested) { start(); return; }   // first press: the test, as before
      on[kind] = !on[kind];
      reflect(kind);
      start();                            // rebuild the preview with only the devices that are on (a switched-off camera's light goes out)
    }

    chips.camera.addEventListener('click', function () { toggle('camera'); });
    chips.microphone.addEventListener('click', function () { toggle('microphone'); });
    chips.settings.addEventListener('click', function () {
      var open = panel.hidden;
      panel.hidden = !open;
      chips.settings.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { fillDevices(); }
    });
    camSelect.addEventListener('change', start);
    micSelect.addEventListener('change', start);

    // Already allowed in this browser: show the preview without asking again.
    if (navigator.permissions && navigator.permissions.query) {
      navigator.permissions.query({ name: 'camera' }).then(function (r) { if (r.state === 'granted') { start(); } }).catch(function () { /* unsupported */ });
    }
  }

  var preview = document.querySelector('[data-telehealth-preview]');
  if (preview) { initPreview(preview); }
  window.addEventListener('pagehide', stopAll);

  // Join is a plain form post (the call page follows); free the camera first.
  var join = document.querySelector('[data-telehealth-join]');
  if (join) { join.addEventListener('submit', stopAll); }

  Array.prototype.forEach.call(document.querySelectorAll('[data-telehealth-copy]'), function (copy) {
    var label = copy.querySelector('[data-copy-label]');
    var original = label.textContent;
    copy.addEventListener('click', function () {
      var url = copy.getAttribute('data-url');
      var done = function (text) { label.textContent = text; setTimeout(function () { label.textContent = original; }, 2200); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () { done('Link copied'); }, function () { done('Copy failed — select the link manually'); });
        return;
      }
      var box = document.createElement('textarea');
      box.value = url; box.setAttribute('readonly', ''); box.style.position = 'fixed'; box.style.opacity = '0';
      document.body.appendChild(box); box.select();
      try { document.execCommand('copy'); done('Link copied'); } catch (e) { done('Copy failed — select the link manually'); }
      document.body.removeChild(box);
    });
  });
}());
