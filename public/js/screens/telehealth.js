/*
 * Telehealth screens. No dependencies and no network.
 *
 *  - Device preview (join page and "Test Connection"): shows YOUR camera in this tab and a microphone level.
 *    Nothing is uploaded, recorded or stored; the tracks are stopped when you leave or press Join, so the
 *    call page's video frame gets the camera.
 *  - Copy Meeting Link (join and call pages): copies the client's link to the clipboard.
 *  The call itself is Daily Prebuilt in an iframe; this script does not touch it.
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

    function start() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        say('This browser cannot access a camera or microphone here.');
        mark(chips.camera, 'bad'); mark(chips.microphone, 'bad');
        return;
      }
      stopAll();
      say('Asking your browser for the camera and microphone…');
      var constraints = {
        video: camSelect.value ? { deviceId: { exact: camSelect.value } } : true,
        audio: micSelect.value ? { deviceId: { exact: micSelect.value } } : true
      };
      navigator.mediaDevices.getUserMedia(constraints).then(function (s) {
        stream = s;
        video.srcObject = s;
        video.hidden = false;
        stage.classList.add('is-live');
        video.play().catch(function () { /* autoplay refused: the poster stays */ });
        mark(chips.camera, s.getVideoTracks().length ? 'ok' : 'bad');
        mark(chips.microphone, s.getAudioTracks().length ? 'ok' : 'bad');
        say(s.getVideoTracks().length && s.getAudioTracks().length ? 'Your camera and microphone work. Only you can see this preview.' : 'Part of your setup was not found.');
        meter(s);
        fillDevices();
      }).catch(function (e) {
        mark(chips.camera, 'bad'); mark(chips.microphone, 'bad');
        say(e && e.name === 'NotAllowedError'
          ? 'Your browser blocked the camera or microphone. Allow them from the address bar, then press Camera again.'
          : 'No camera or microphone was found, or another app is using it.');
      });
    }

    chips.camera.addEventListener('click', start);
    chips.microphone.addEventListener('click', start);
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
