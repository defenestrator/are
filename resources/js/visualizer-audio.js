// Audio sources for the Three.js visualizer (resources/js/visualizer.js).
//
// No imports and no globals, so node:test can exercise it with fakes
// (resources/js/tests/visualizer-audio.test.js). The settings come from
// #visualizer-config, rendered by App\View\Components\visualizer.

export const LOG_PREFIX = '[ARE visualizer]';

// Same analysis as THREE.AudioAnalyser(sound, 32), which the visualizer used
// before: 16 frequency bins, averaged. The shader is tuned for this range.
export const FFT_SIZE = 32;

// Analysing a show mix, not a voice call: no processing on the signal.
const RAW_AUDIO = {echoCancellation: false, noiseSuppression: false, autoGainControl: false};

export class CaptureError extends Error {
    /**
     * @param {'unsupported'|'denied'|'no-device'|'error'} code
     * @param {string} message
     * @param {string[]} labels capture devices the page could see
     */
    constructor(code, message, labels = []) {
        super(message);
        this.code = code;
        this.labels = labels;
    }

    /** Worth trying again later: a device can appear after OBS starts. */
    get retryable() {
        return this.code === 'no-device' || this.code === 'error';
    }
}

/**
 * @param {{dataset: Record<string, string|undefined>}|null} element
 */
export function readConfig(element) {
    const data = element?.dataset ?? {};
    const gain = Number.parseFloat(data.audioGain ?? '');
    const mode = ['device', 'file', 'none'].includes(data.audioMode) ? data.audioMode : 'none';

    return {
        mode,
        device: data.audioDevice ?? '',
        gain: Number.isFinite(gain) && gain > 0 ? gain : 1,
        audible: data.audioAudible === 'true',
        motion: data.motion === 'orbit' ? 'orbit' : 'mouse',
    };
}

/**
 * Choose the capture device the operator asked for. An exact label wins,
 * then a deviceId, then the first label containing the text, all
 * case-insensitive. An empty request means the default device.
 *
 * @param {{kind: string, label: string, deviceId: string}[]} devices
 * @param {string} wanted
 */
export function pickAudioInput(devices, wanted) {
    const inputs = devices.filter((device) => device.kind === 'audioinput');
    const needle = wanted.trim().toLowerCase();

    if (needle === '') {
        return inputs.find((device) => device.deviceId === 'default') ?? inputs[0] ?? null;
    }

    return inputs.find((device) => device.label.toLowerCase() === needle)
        ?? inputs.find((device) => device.deviceId === wanted.trim())
        ?? inputs.find((device) => device.label.toLowerCase().includes(needle))
        ?? null;
}

/** Average of the byte frequency data, 0 to 255. */
export function averageFrequency(analyser, buffer) {
    if (!analyser) {
        return 0;
    }

    analyser.getByteFrequencyData(buffer);

    let sum = 0;
    for (const value of buffer) {
        sum += value;
    }

    return buffer.length === 0 ? 0 : sum / buffer.length;
}

/** The level the shader gets: scaled by ?gain and kept in byte range. */
export function scaledLevel(level, gain) {
    return Math.min(255, Math.max(0, level * gain));
}

export function createAnalyser(context) {
    const analyser = context.createAnalyser();
    analyser.fftSize = FFT_SIZE;

    return analyser;
}

function stopStream(stream) {
    stream?.getTracks().forEach((track) => track.stop());
}

function toCaptureError(error, labels = []) {
    if (error instanceof CaptureError) {
        return error;
    }

    switch (error?.name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return new CaptureError('denied', `Capture permission denied (${error.name}).`, labels);
        case 'NotFoundError':
        case 'OverconstrainedError':
            return new CaptureError('no-device', `No matching capture device (${error.name}).`, labels);
        default:
            return new CaptureError('error', `Capture failed: ${error?.name ?? 'Error'}: ${error?.message ?? error}`, labels);
    }
}

/**
 * Open the requested capture device and attach an analyser to it. The
 * analyser is never connected to the context's destination, so the captured
 * audio is measured but not played back (no feedback into the stream).
 *
 * Device labels are hidden until the page holds a capture permission, so a
 * labelled request first opens the default device, reads the labels, then
 * switches to the match.
 *
 * @returns {Promise<{analyser: AnalyserNode, stream: MediaStream, label: string}>}
 */
export async function openCapture(context, wanted, mediaDevices) {
    if (!mediaDevices?.getUserMedia || !mediaDevices?.enumerateDevices) {
        throw new CaptureError(
            'unsupported',
            'navigator.mediaDevices is unavailable. The page must be served over HTTPS (or localhost), '
            + 'and OBS must be launched with --enable-media-stream.',
        );
    }

    let stream;
    let labels = [];

    try {
        stream = await mediaDevices.getUserMedia({audio: {...RAW_AUDIO}, video: false});

        const devices = await mediaDevices.enumerateDevices();
        labels = devices.filter((device) => device.kind === 'audioinput').map((device) => device.label);

        const match = pickAudioInput(devices, wanted);

        if (!match) {
            throw new CaptureError('no-device', `No capture device matches "${wanted}".`, labels);
        }

        const current = stream.getAudioTracks()[0]?.getSettings?.().deviceId;

        if (wanted.trim() !== '' && match.deviceId !== current) {
            stopStream(stream);
            stream = await mediaDevices.getUserMedia({audio: {...RAW_AUDIO, deviceId: {exact: match.deviceId}}, video: false});
        }

        const analyser = createAnalyser(context);
        context.createMediaStreamSource(stream).connect(analyser);

        return {analyser, stream, label: match.label || stream.getAudioTracks()[0]?.label || 'default'};
    } catch (error) {
        stopStream(stream);
        throw toCaptureError(error, labels);
    }
}

/**
 * Loop the bundled demo track through an analyser. It reaches the speakers
 * only when `audible` is true, which App\Support\VisualizerAudio allows on
 * /visualizer and never on the overlay.
 */
export async function openFile(context, url, audible, fetchImpl) {
    const response = await fetchImpl(url);
    const buffer = await context.decodeAudioData(await response.arrayBuffer());

    const source = context.createBufferSource();
    source.buffer = buffer;
    source.loop = true;

    const analyser = createAnalyser(context);
    source.connect(analyser);

    if (audible) {
        analyser.connect(context.destination);
    }

    return {analyser, source};
}

/**
 * Keep the visualizer fed from a capture device for as long as the page
 * lives: retry while the device is missing, and reopen it if it goes away
 * (unplugged, or a virtual cable restarted). Permission errors are final,
 * because they need OBS relaunched with the right flag.
 *
 * @param {{onAnalyser: (a: AnalyserNode|null) => void, onStatus: (s: string, detail: string) => void}} hooks
 */
export function keepCapturing(context, wanted, mediaDevices, hooks, {retryMs = 5000, setTimer = setTimeout} = {}) {
    const attempt = async () => {
        try {
            const {analyser, stream, label} = await openCapture(context, wanted, mediaDevices);

            hooks.onAnalyser(analyser);
            hooks.onStatus('live', `Listening to "${label}".`);

            stream.getAudioTracks().forEach((track) => track.addEventListener('ended', () => {
                hooks.onAnalyser(null);
                hooks.onStatus('ended', `"${label}" stopped. Reopening in ${retryMs / 1000}s.`);
                setTimer(attempt, retryMs);
            }, {once: true}));
        } catch (error) {
            const failure = toCaptureError(error);
            const seen = failure.labels.length ? ` Devices seen: ${failure.labels.map((l) => `"${l}"`).join(', ')}.` : '';

            hooks.onAnalyser(null);
            hooks.onStatus(failure.code, failure.message + seen + (failure.retryable ? ` Retrying in ${retryMs / 1000}s.` : ''));

            if (failure.retryable) {
                setTimer(attempt, retryMs);
            }
        }
    };

    return attempt();
}
