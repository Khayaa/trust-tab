/**
 * Isolated barcode capture. Blade and Livewire never call getUserMedia.
 *
 * Order: Mini App host scanner, then the camera. Chrome Android uses the
 * Barcode Detection API. iOS Chrome/Safari have a camera but no detector,
 * so those phones decode frames with ZXing. Typing the number is last.
 */

const EAN_FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'codabar'];

let stream = null;
let detector = null;
let zxingControls = null;
let rafId = 0;
let lastCode = '';
let lastAt = 0;
let wireId = null;
let wireMethod = 'findByBarcode';

function miniAppScan() {
    return window.TrustTabMiniApp?.scanBarcode ?? window.MoMoMiniApp?.scanBarcode ?? null;
}

function canUseMiniApp() {
    return typeof miniAppScan() === 'function';
}

function canUseCamera() {
    return Boolean(navigator.mediaDevices?.getUserMedia);
}

function hasNativeDetector() {
    return 'BarcodeDetector' in window;
}

function overlay() {
    return document.getElementById('barcode-scan-overlay');
}

function videoEl() {
    return document.querySelector('[data-barcode-video]');
}

function errorEl() {
    return document.querySelector('[data-barcode-error]');
}

function setError(message) {
    const el = errorEl();

    if (! el) {
        return;
    }

    el.textContent = message;
    el.hidden = message === '';
}

function showOverlay() {
    const el = overlay();

    if (el) {
        el.hidden = false;
    }
}

function hideOverlay() {
    const el = overlay();

    if (el) {
        el.hidden = true;
    }
}

async function createDetector() {
    const supported =
        typeof BarcodeDetector.getSupportedFormats === 'function'
            ? await BarcodeDetector.getSupportedFormats()
            : EAN_FORMATS;

    const formats = EAN_FORMATS.filter((format) => supported.includes(format));

    return new BarcodeDetector({
        formats: formats.length > 0 ? formats : supported,
    });
}

function stopTracks() {
    if (rafId) {
        cancelAnimationFrame(rafId);
        rafId = 0;
    }

    if (zxingControls) {
        zxingControls.stop();
        zxingControls = null;
    }

    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    detector = null;
}

async function tick(video, onDetect, onError) {
    if (! detector || ! video.srcObject) {
        return;
    }

    if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
        try {
            const codes = await detector.detect(video);
            const value = codes[0]?.rawValue?.trim();
            const now = Date.now();

            if (value && (value !== lastCode || now - lastAt > 1500)) {
                lastCode = value;
                lastAt = now;
                onDetect(value);
                stop();

                return;
            }
        } catch (error) {
            onError(error.message ?? 'Could not read that barcode.');
            stop();

            return;
        }
    }

    rafId = requestAnimationFrame(() => tick(video, onDetect, onError));
}

function acceptCode(value, onDetect) {
    const now = Date.now();

    if (! value || (value === lastCode && now - lastAt <= 1500)) {
        return false;
    }

    lastCode = value;
    lastAt = now;
    onDetect(value);

    return true;
}

async function startZxing(video, { onDetect, onError }) {
    try {
        const { BrowserMultiFormatReader } = await import('@zxing/browser');
        const reader = new BrowserMultiFormatReader();

        zxingControls = await reader.decodeFromStream(stream, video, (result) => {
            const value = result?.getText()?.trim();

            if (value && acceptCode(value, onDetect)) {
                stop();
            }
        });
    } catch (error) {
        onError(error.message ?? 'Could not read that barcode. Type the number instead.');
        stop();
    }
}

async function openCamera(video) {
    stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: {
            facingMode: { ideal: 'environment' },
        },
    });

    video.srcObject = stream;
    video.setAttribute('playsinline', 'true');
    video.setAttribute('webkit-playsinline', 'true');
    await video.play();
}

async function start(video, { onDetect, onError }) {
    stopTracks();

    if (! canUseCamera()) {
        throw new Error('This browser cannot open the camera. Type the number instead.');
    }

    await openCamera(video);

    if (hasNativeDetector()) {
        detector = await createDetector();
        rafId = requestAnimationFrame(() => tick(video, onDetect, onError));

        return;
    }

    await startZxing(video, { onDetect, onError });
}

function stop() {
    const video = videoEl();

    if (video) {
        video.srcObject = null;
    }

    stopTracks();
    hideOverlay();
}

async function scanWithMiniApp() {
    const scan = miniAppScan();

    if (typeof scan !== 'function') {
        throw new Error('Camera scan is not available here. Type the number instead.');
    }

    const value = String((await scan()) ?? '').trim();

    if (value === '') {
        throw new Error('No barcode came back. Type the number instead.');
    }

    return value;
}

function submitCode(code) {
    if (! wireId || ! window.Livewire) {
        return;
    }

    window.Livewire.find(wireId).call(wireMethod, code);
}

function wireIdFrom(el) {
    return el.closest('[wire\\:id]')?.getAttribute('wire:id') ?? null;
}

async function openFrom(trigger) {
    setError('');
    wireId = wireIdFrom(trigger);
    wireMethod = trigger.getAttribute('data-barcode-action') || 'findByBarcode';

    if (canUseMiniApp()) {
        try {
            submitCode(await scanWithMiniApp());
        } catch (error) {
            setError(error.message ?? 'Could not scan.');
        }

        return;
    }

    if (! canUseCamera()) {
        setError('This browser cannot open the camera. Type the number instead.');

        return;
    }

    const video = videoEl();

    if (! video) {
        setError('Camera scan is not available here. Type the number instead.');

        return;
    }

    showOverlay();

    try {
        await start(video, {
            onDetect: (code) => {
                stop();
                submitCode(code);
            },
            onError: (message) => {
                setError(message);
            },
        });
    } catch (error) {
        stop();
        setError(error.message ?? 'Could not open the camera.');
    }
}

function eventElement(event) {
    return event.target instanceof Element ? event.target : event.target.parentElement;
}

document.addEventListener('click', (event) => {
    const target = eventElement(event);

    if (! target) {
        return;
    }

    const scan = target.closest('[data-barcode-scan]');

    if (scan) {
        event.preventDefault();
        openFrom(scan);

        return;
    }

    if (target.closest('[data-barcode-cancel]')) {
        event.preventDefault();
        stop();
        setError('');
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && overlay() && ! overlay().hidden) {
        stop();
        setError('');
    }
});

document.addEventListener('livewire:navigating', stop);
window.addEventListener('pagehide', stop);

window.TrustTabBarcode = {
    canUseCamera,
    canUseMiniApp,
    hasNativeDetector,
    start,
    stop,
    scanWithMiniApp,
};
