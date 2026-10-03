{{-- The device preview panel (comp 11): a real local camera preview and microphone level. Nothing is uploaded or recorded; the script (public/js/screens/telehealth.js) only reads the devices in this tab. Once the preview runs, Camera and Microphone switch that device off/on; on the join page the choice goes with the Join form, so the call starts that way. --}}
<div class="tj-preview" data-telehealth-preview>
    <video class="tj-preview__video" playsinline muted hidden aria-label="Your camera preview"></video>
    <div class="tj-preview__stage">
        <span class="tj-preview__icon"><x-ui.icon name="video" :size="30" :stroke="1.9" /></span>
        <h2 class="tj-preview__title">Ready to join?</h2>
        <p class="tj-preview__text">Make sure your camera and microphone are working.</p>
    </div>
    <div class="tj-chips">
        <button type="button" class="tj-chip" data-chip="camera" aria-label="Test camera"><span class="tj-chip__on"><x-ui.icon name="video" :size="21" :stroke="2" /></span><span class="tj-chip__off"><x-ui.icon name="video-off" :size="21" :stroke="2" /></span><span>Camera</span><span class="tj-chip__ok" aria-hidden="true"><x-ui.icon name="check" :size="10" :stroke="3.4" /></span></button>
        <button type="button" class="tj-chip" data-chip="microphone" aria-label="Test microphone"><span class="tj-chip__on"><x-ui.icon name="mic" :size="21" :stroke="2" /></span><span class="tj-chip__off"><x-ui.icon name="mic-off" :size="21" :stroke="2" /></span><span>Microphone</span><span class="tj-chip__ok" aria-hidden="true"><x-ui.icon name="check" :size="10" :stroke="3.4" /></span><span class="tj-chip__level" data-level hidden aria-hidden="true"></span></button>
        <button type="button" class="tj-chip" data-chip="settings" aria-expanded="false" aria-controls="tj-devices"><x-ui.icon name="settings" :size="19" :stroke="2" /><span>Settings</span></button>
    </div>
    <div class="tj-devices" id="tj-devices" hidden>
        <label>Camera <select data-device="videoinput"></select></label>
        <label>Microphone <select data-device="audioinput"></select></label>
    </div>
    <p class="tj-preview__msg" role="status" aria-live="polite" data-status></p>
</div>
