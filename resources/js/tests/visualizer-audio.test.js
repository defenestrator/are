// Run with: npm run test:js (node --test, no dependencies).
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    CaptureError,
    FFT_SIZE,
    averageFrequency,
    keepCapturing,
    openCapture,
    openFile,
    pickAudioInput,
    readConfig,
    scaledLevel,
} from '../visualizer-audio.js';

const DEVICES = [
    {kind: 'audioinput', deviceId: 'default', label: 'Default - MacBook Pro Microphone'},
    {kind: 'audioinput', deviceId: 'mic-1', label: 'MacBook Pro Microphone'},
    {kind: 'audioinput', deviceId: 'bh-2', label: 'BlackHole 2ch (Virtual)'},
    {kind: 'audioinput', deviceId: 'cable', label: 'CABLE Output (VB-Audio Virtual Cable)'},
    {kind: 'audiooutput', deviceId: 'speakers', label: 'BlackHole 2ch (Virtual)'},
    {kind: 'videoinput', deviceId: 'cam', label: 'FaceTime HD Camera'},
];

function fakeStream(deviceId, label) {
    const listeners = {};
    const track = {
        label,
        stopped: false,
        stop() { this.stopped = true; },
        getSettings: () => ({deviceId}),
        addEventListener(type, handler) { listeners[type] = handler; },
        end() { listeners.ended?.(); },
    };

    return {track, getTracks: () => [track], getAudioTracks: () => [track]};
}

function fakeMediaDevices({devices = DEVICES, failWith = null} = {}) {
    const calls = [];
    const streams = [];

    return {
        calls,
        streams,
        async getUserMedia(constraints) {
            calls.push(constraints);
            if (failWith) {
                const error = new Error('nope');
                error.name = failWith;
                throw error;
            }
            const wanted = constraints.audio.deviceId?.exact ?? 'default';
            const device = devices.find((d) => d.kind === 'audioinput' && d.deviceId === wanted);
            const stream = fakeStream(wanted, device?.label ?? '');
            streams.push(stream);

            return stream;
        },
        async enumerateDevices() {
            return devices;
        },
    };
}

function fakeContext() {
    const connections = [];
    const destination = {name: 'destination'};
    const node = (name) => ({name, connect(target) { connections.push([name, target.name ?? target]); }});

    return {
        connections,
        destination,
        createAnalyser: () => ({...node('analyser'), fftSize: 2048, frequencyBinCount: 16, getByteFrequencyData() {}}),
        createMediaStreamSource: () => node('capture'),
        createBufferSource: () => ({...node('file'), loop: false, buffer: null}),
        decodeAudioData: async (bytes) => ({decoded: bytes}),
    };
}

test('readConfig reads the server-rendered settings and falls back safely', () => {
    const el = {dataset: {audioMode: 'device', audioDevice: 'BlackHole', audioGain: '2.5', audioAudible: 'false', motion: 'orbit'}};
    assert.deepEqual(readConfig(el), {mode: 'device', device: 'BlackHole', gain: 2.5, audible: false, motion: 'orbit'});

    assert.deepEqual(readConfig(null), {mode: 'none', device: '', gain: 1, audible: false, motion: 'mouse'});
    assert.equal(readConfig({dataset: {audioMode: 'nonsense', audioGain: '-3'}}).mode, 'none');
    assert.equal(readConfig({dataset: {audioGain: 'abc'}}).gain, 1);
});

test('pickAudioInput prefers an exact label, then a deviceId, then a substring', () => {
    assert.equal(pickAudioInput(DEVICES, 'macbook pro microphone').deviceId, 'mic-1');
    assert.equal(pickAudioInput(DEVICES, 'cable').deviceId, 'cable');
    assert.equal(pickAudioInput(DEVICES, 'BLACKHOLE').deviceId, 'bh-2');
    assert.equal(pickAudioInput(DEVICES, 'Microphone').deviceId, 'default');
});

test('pickAudioInput ignores outputs and cameras, and returns null for no match', () => {
    assert.equal(pickAudioInput(DEVICES, 'speakers'), null);
    assert.equal(pickAudioInput(DEVICES, 'FaceTime'), null);
    assert.equal(pickAudioInput(DEVICES, 'Focusrite'), null);
});

test('pickAudioInput with no label picks the default input', () => {
    assert.equal(pickAudioInput(DEVICES, '').deviceId, 'default');
    assert.equal(pickAudioInput(DEVICES.slice(1), '  ').deviceId, 'mic-1');
    assert.equal(pickAudioInput([], ''), null);
});

test('averageFrequency and scaledLevel match the old 0-255 range', () => {
    const analyser = {getByteFrequencyData(buffer) { buffer.set([0, 100, 200, 100]); }};
    assert.equal(averageFrequency(analyser, new Uint8Array(4)), 100);
    assert.equal(averageFrequency(null, new Uint8Array(4)), 0);
    assert.equal(scaledLevel(100, 2), 200);
    assert.equal(scaledLevel(200, 5), 255);
});

test('openCapture switches from the default device to the labelled one, unprocessed', async () => {
    const media = fakeMediaDevices();
    const context = fakeContext();

    const {analyser, label} = await openCapture(context, 'BlackHole', media);

    assert.equal(label, 'BlackHole 2ch (Virtual)');
    assert.equal(analyser.fftSize, FFT_SIZE);
    assert.equal(media.calls.length, 2);
    assert.deepEqual(media.calls[1].audio.deviceId, {exact: 'bh-2'});
    assert.equal(media.calls[1].audio.echoCancellation, false);
    assert.equal(media.calls[1].audio.autoGainControl, false);
    assert.equal(media.streams[0].track.stopped, true, 'the probe stream on the default device is released');
});

test('openCapture never connects captured audio to the speakers', async () => {
    const context = fakeContext();
    await openCapture(context, 'default', fakeMediaDevices());
    await openCapture(context, '', fakeMediaDevices());

    assert.ok(context.connections.every(([, target]) => target !== 'destination'));
});

test('openCapture with no label keeps the first stream', async () => {
    const media = fakeMediaDevices();
    await openCapture(fakeContext(), '', media);

    assert.equal(media.calls.length, 1);
    assert.equal(media.streams[0].track.stopped, false);
});

test('openCapture reports what went wrong, with the devices it could see', async () => {
    await assert.rejects(openCapture(fakeContext(), 'Focusrite', fakeMediaDevices()), (error) => {
        assert.ok(error instanceof CaptureError);
        assert.equal(error.code, 'no-device');
        assert.ok(error.labels.includes('BlackHole 2ch (Virtual)'));
        assert.ok(!error.labels.includes('FaceTime HD Camera'));
        assert.equal(error.retryable, true);

        return true;
    });

    await assert.rejects(openCapture(fakeContext(), '', fakeMediaDevices({failWith: 'NotAllowedError'})), {code: 'denied'});
    await assert.rejects(openCapture(fakeContext(), '', undefined), {code: 'unsupported'});
    await assert.rejects(openCapture(fakeContext(), '', {}), {code: 'unsupported'});
});

test('keepCapturing retries a missing device but not a refused permission', async () => {
    const timers = [];
    const statuses = [];
    const hooks = {onAnalyser() {}, onStatus: (status) => statuses.push(status)};
    const setTimer = (fn, ms) => timers.push({fn, ms});

    await keepCapturing(fakeContext(), 'Focusrite', fakeMediaDevices(), hooks, {retryMs: 5000, setTimer});
    assert.deepEqual(statuses, ['no-device']);
    assert.equal(timers.length, 1);
    assert.equal(timers[0].ms, 5000);

    await keepCapturing(fakeContext(), '', fakeMediaDevices({failWith: 'NotAllowedError'}), hooks, {setTimer});
    assert.deepEqual(statuses, ['no-device', 'denied']);
    assert.equal(timers.length, 1, 'a denied permission needs OBS relaunched, so no retry');
});

test('keepCapturing reopens the device when its track ends', async () => {
    const timers = [];
    const analysers = [];
    const statuses = [];
    const media = fakeMediaDevices();
    const hooks = {onAnalyser: (a) => analysers.push(a), onStatus: (s) => statuses.push(s)};

    await keepCapturing(fakeContext(), 'BlackHole', media, hooks, {setTimer: (fn) => timers.push(fn)});
    assert.deepEqual(statuses, ['live']);

    media.streams.at(-1).track.end();
    assert.deepEqual(statuses, ['live', 'ended']);
    assert.equal(analysers.at(-1), null, 'the dead analyser is dropped');

    await timers[0]();
    assert.deepEqual(statuses, ['live', 'ended', 'live']);
    assert.notEqual(analysers.at(-1), null);
});

test('openFile plays the bundled track aloud only when audible', async () => {
    const fetchImpl = async () => ({arrayBuffer: async () => 'bytes'});

    const silent = fakeContext();
    const {source} = await openFile(silent, '/track.mp3', false, fetchImpl);
    assert.equal(source.loop, true);
    assert.deepEqual(source.buffer, {decoded: 'bytes'});
    assert.ok(silent.connections.every(([, target]) => target !== 'destination'));

    const loud = fakeContext();
    await openFile(loud, '/track.mp3', true, fetchImpl);
    assert.ok(loud.connections.some(([, target]) => target === 'destination'));
});
