// Run with: npm run test:js (node --test, no dependencies).
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    CLOSE_GRACE_MS,
    HEARTBEAT_JITTER_MS,
    HEARTBEAT_MIN_MS,
    POLL_JITTER_MS,
    POLL_MIN_MS,
    REFRESH_JITTER_MS,
    REFRESH_MIN_MS,
    TICK_MS,
    clock,
    liveBus,
    percent,
    shape,
} from '../live-bus.js';

// Just enough DOM: data-* attribute selectors, dataset, textContent, style.
class El {
    constructor(dataset = {}, textContent = '') {
        this.dataset = {...dataset};
        this.textContent = String(textContent);
        this.style = {};
        this.children = [];
    }

    add(...nodes) {
        this.children.push(...nodes);

        return this;
    }

    descendants() {
        return this.children.flatMap((child) => [child, ...child.descendants()]);
    }

    querySelector(selector) {
        const [, attr, value] = selector.match(/^\[data-([\w-]+)(?:="([^"]*)")?\]$/);
        const key = attr.replace(/-(\w)/g, (_, c) => c.toUpperCase());

        return this.descendants().find((el) => key in el.dataset && (value === undefined || String(el.dataset[key]) === value)) ?? null;
    }
}

function snapshot({version = 1, game = 'orkestera', running = true, killed = false, paused = false, mode = 'democracy', window = null, awaiting = null, result = null} = {}) {
    return {version, game: game && {key: game, label: 'Chat Plays Orkestera'}, running, killed, paused, mode, mode_label: mode, window, awaiting, result};
}

function openWindow(options = [[1, 'move', '3', 2], [2, 'move', '7', 1]], {id = 10, secondsLeft = 60} = {}) {
    const opts = options.map(([number, verb, label, votes, vetoed = false]) => ({number, verb, label, votes, vetoed}));

    return {id, seconds_left: secondsLeft, closes_at: '2026-10-05T00:01:00Z', total: opts.reduce((s, o) => s + o.votes, 0), options: opts};
}

/**
 * A bus overlay with a controllable clock and timers and a fake Echo. `snap`
 * is what the server rendered into data-snapshot; `status` is the socket at
 * start, or null for a build without Echo.
 */
function overlay(snap, {status = 'connected', live = 'on', games = ['orkestera', 'other']} = {}) {
    const root = new El({live, busGames: JSON.stringify(games), snapshot: JSON.stringify(snap)});
    const render = (s) => {
        root.children = [];
        for (const o of s?.window?.options ?? []) {
            root.add(new El({option: String(o.number)}).add(new El({optionVotes: ''}, String(o.votes)), new El({optionBar: ''})));
        }
        if (s?.window && !s.paused) {
            root.add(new El({countdown: ''}, clock(s.window.seconds_left)));
        }
    };
    render(snap);

    let time = 1_000_000;
    let timers = [];
    let nextId = 0;
    let ticker = null;
    let refreshes = 0;
    let serverNext = null;
    const listeners = {};
    const stopped = [];
    const subscribed = [];
    let current = status;
    let watcher = null;
    const echo = {
        channel(name) {
            subscribed.push(name);
            const channel = {
                listen(event, handler) { listeners[`${name} ${event}`] = handler; return channel; },
                stopListening(event) { stopped.push(`${name} ${event}`); return channel; },
            };

            return channel;
        },
        connectionStatus: () => current,
        connector: {onConnectionChange(callback) { watcher = callback; return () => { watcher = null; }; }},
    };

    const component = liveBus({
        echo: () => (status === null ? undefined : echo),
        random: () => 0.5,
        now: () => time,
        setTimer: (fn, ms) => { const id = ++nextId; timers.push({id, fn, ms}); return id; },
        clearTimer: (id) => { timers = timers.filter((t) => t.id !== id); },
        setTicker: (fn) => { ticker = fn; return 'ticker'; },
        clearTicker: () => { ticker = null; },
    });
    Object.assign(component, {
        $root: root,
        $wire: {
            $refresh: () => {
                refreshes++;
                if (serverNext) {
                    if (serverNext.live) {
                        root.dataset.live = serverNext.live;
                    }
                    root.dataset.snapshot = JSON.stringify(serverNext.snapshot ?? null);
                    render(serverNext.snapshot ?? null);
                    serverNext = null;
                }

                return Promise.resolve();
            },
        },
    });
    component.init();

    return {
        component,
        root,
        subscribed,
        stopped,
        fire: (channel, event, payload) => listeners[`${channel} ${event}`](payload),
        setStatus: (next) => { current = next; watcher?.(next); },
        timers: () => timers.map((t) => t.ms).sort((a, b) => a - b),
        refreshes: () => refreshes,
        // The next server render returns this.
        serverReturns: (next) => { serverNext = next; },
        votes: (n) => root.querySelector(`[data-option="${n}"]`)?.querySelector('[data-option-votes]')?.textContent,
        bar: (n) => root.querySelector(`[data-option="${n}"]`)?.querySelector('[data-option-bar]')?.style.width,
        countdown: () => root.querySelector('[data-countdown]')?.textContent,
        advance: (ms) => { time += ms; ticker?.(); },
        hasTicker: () => ticker !== null,
        async runTimers() {
            const due = timers;
            timers = [];
            due.forEach((t) => t.fn());
            await Promise.resolve();
            await Promise.resolve();
        },
    };
}

const REFRESH = REFRESH_MIN_MS + 0.5 * REFRESH_JITTER_MS;
const POLL = POLL_MIN_MS + 0.5 * POLL_JITTER_MS;
const HEARTBEAT = HEARTBEAT_MIN_MS + 0.5 * HEARTBEAT_JITTER_MS;

// Helpers

test('clock and percent format the countdown and the bars', () => {
    assert.equal(clock(65), '1:05');
    assert.equal(clock(0.2), '0:01');
    assert.equal(clock(-3), '0:00');
    assert.equal(percent(1, 3), 33);
    assert.equal(percent(0, 0), 0);
});

test('shape ignores counts and time but not options, labels, vetoes or state', () => {
    const a = snapshot({window: openWindow()});
    const counts = snapshot({version: 2, window: openWindow([[1, 'move', '3', 9], [2, 'move', '7', 4]], {secondsLeft: 12})});

    assert.equal(shape(a), shape(counts));
    assert.notEqual(shape(a), shape(snapshot({window: openWindow([[1, 'move', '3', 2]])})));
    assert.notEqual(shape(a), shape(snapshot({window: openWindow([[1, 'move', '3', 2], [2, 'move', '7', 1, true]])})));
    assert.notEqual(shape(a), shape(snapshot({paused: true, window: openWindow()})));
    assert.notEqual(shape(a), shape(snapshot({window: openWindow(undefined, {id: 11})})));
    assert.notEqual(shape(a), shape(snapshot({awaiting: {verb: 'task', votes: 3, total: 4, count: 1}})));
    assert.equal(shape(null), 'none');
});

// Connection and fallback

test('connected, it listens on every game\'s bus channel and keeps a slow heartbeat', () => {
    const o = overlay(snapshot({window: openWindow()}));

    assert.deepEqual(o.subscribed, ['bus.orkestera', 'bus.other']);
    assert.deepEqual(o.timers(), [HEARTBEAT]);
});

test('without Echo it polls every 2-4 s, which also re-checks the token', async () => {
    const o = overlay(snapshot({window: openWindow()}), {status: null});

    assert.deepEqual(o.subscribed, []);
    assert.deepEqual(o.timers(), [POLL]);
    await o.runTimers();
    assert.equal(o.refreshes(), 1);
    assert.deepEqual(o.timers(), [POLL]);
});

test('a dropped socket polls, and reconnecting stops it and refreshes once', async () => {
    const o = overlay(snapshot({window: openWindow()}));

    o.setStatus('unavailable');
    assert.deepEqual(o.timers(), [POLL]);

    o.setStatus('connected');
    assert.deepEqual(o.timers(), [REFRESH, HEARTBEAT]);
});

test('data-live="off" at start does nothing, and a refresh that turns it off stops everything', async () => {
    const off = overlay(null, {live: 'off'});
    assert.deepEqual(off.subscribed, []);
    assert.deepEqual(off.timers(), []);
    assert.equal(off.hasTicker(), false);

    const o = overlay(snapshot({window: openWindow()}));
    o.serverReturns({live: 'off', snapshot: null});
    await o.runTimers();

    assert.equal(o.stopped.length, 8, 'four events on each of two channels');
    assert.deepEqual(o.timers(), []);
    assert.equal(o.hasTicker(), false);
    o.setStatus('unavailable');
    assert.deepEqual(o.timers(), [], 'no polling after the token was rotated');
});

// bus.tally

test('a tally with only new counts updates them in place, with no request', () => {
    const o = overlay(snapshot({window: openWindow()}));

    o.fire('bus.orkestera', '.bus.tally', snapshot({version: 2, window: openWindow([[1, 'move', '3', 5], [2, 'move', '7', 3]], {secondsLeft: 30})}));

    assert.equal(o.votes(1), '5');
    assert.equal(o.votes(2), '3');
    assert.equal(o.bar(1), '63%');
    assert.equal(o.bar(2), '38%');
    assert.deepEqual(o.timers(), [HEARTBEAT]);
    o.advance(TICK_MS);
    assert.equal(o.countdown(), '0:30', 'the countdown follows the server\'s seconds left');
});

test('a tally older than the one shown is dropped', () => {
    const o = overlay(snapshot({version: 5, window: openWindow()}));

    o.fire('bus.orkestera', '.bus.tally', snapshot({version: 4, window: openWindow([[1, 'move', '3', 99], [2, 'move', '7', 1]])}));

    assert.equal(o.votes(1), '2');
});

test('a tally that changes the shape asks the server to re-render', () => {
    const o = overlay(snapshot({window: openWindow()}));

    o.fire('bus.orkestera', '.bus.tally', snapshot({version: 2, window: openWindow([[1, 'move', '3', 2], [2, 'move', '7', 1], [3, 'move', '9', 1]])}));

    assert.deepEqual(o.timers(), [REFRESH, HEARTBEAT]);
    assert.equal(o.votes(1), '2', 'nothing written before the server renders');
});

test('pause, kill, an awaiting winner or a result all re-render', () => {
    for (const next of [
        snapshot({version: 2, paused: true, window: openWindow()}),
        snapshot({version: 2, killed: true}),
        snapshot({version: 2, awaiting: {verb: 'task', votes: 3, total: 3, count: 1}}),
        snapshot({version: 2, result: {status: 'published', verb: 'move', label: '3', votes: 2, total: 3, seconds_left: 20}}),
    ]) {
        const o = overlay(snapshot({window: openWindow()}));
        o.fire('bus.orkestera', '.bus.tally', next);
        assert.ok(o.timers().includes(REFRESH), JSON.stringify(next));
    }
});

test('another game\'s tally matters only once it is running, or for the kill switch', () => {
    const o = overlay(snapshot({window: openWindow()}));
    o.fire('bus.other', '.bus.tally', snapshot({version: 9, game: 'other', running: false}));
    assert.deepEqual(o.timers(), [HEARTBEAT]);

    o.fire('bus.other', '.bus.tally', snapshot({version: 9, game: 'other', running: true}));
    assert.ok(o.timers().includes(REFRESH));

    const idle = overlay({game: null, killed: false});
    idle.fire('bus.orkestera', '.bus.tally', snapshot({version: 9, killed: true, running: false}));
    assert.ok(idle.timers().includes(REFRESH));
});

test('bus.state, bus.action and bus.veto re-render, sharing one refresh', async () => {
    const o = overlay(snapshot({window: openWindow()}));

    o.fire('bus.orkestera', '.bus.state', {killed: false, paused: true});
    o.fire('bus.orkestera', '.bus.action', {id: 1});
    o.fire('bus.orkestera', '.bus.veto', {id: 1});

    assert.equal(o.timers().filter((ms) => ms === REFRESH).length, 1);
});

// The countdown and timed re-renders

test('the countdown runs from the server\'s seconds left and re-renders once after the window closes', async () => {
    const o = overlay(snapshot({window: openWindow(undefined, {secondsLeft: 3})}));

    o.advance(1000);
    assert.equal(o.countdown(), '0:02');
    o.advance(2000);
    assert.equal(o.countdown(), '0:00');
    assert.deepEqual(o.timers(), [HEARTBEAT], 'the server gets a grace period to resolve and tell us');

    o.advance(CLOSE_GRACE_MS);
    assert.ok(o.timers().includes(REFRESH));
    o.advance(1000);
    assert.equal(o.timers().filter((ms) => ms === REFRESH).length, 1, 'only once');
});

test('a paused game has no countdown and never re-renders for it', () => {
    const o = overlay(snapshot({paused: true, window: openWindow(undefined, {secondsLeft: 1})}));

    o.advance(60_000);

    assert.equal(o.countdown(), undefined);
    assert.deepEqual(o.timers(), [HEARTBEAT]);
});

test('a result re-renders once it has been shown long enough', () => {
    const o = overlay(snapshot({result: {status: 'published', verb: 'move', label: '3', votes: 2, total: 2, seconds_left: 5}}));

    o.advance(4000);
    assert.deepEqual(o.timers(), [HEARTBEAT]);
    o.advance(1000);
    assert.ok(o.timers().includes(REFRESH));
});

test('a server render resets the countdown from the new snapshot', async () => {
    const o = overlay(snapshot({window: openWindow(undefined, {secondsLeft: 10})}));
    o.serverReturns({snapshot: snapshot({version: 2, window: openWindow(undefined, {id: 11, secondsLeft: 45})})});

    await o.runTimers();
    o.advance(TICK_MS);

    assert.equal(o.countdown(), '0:45');
});

test('destroy stops listening on every channel and clears its timers and ticker', () => {
    const o = overlay(snapshot({window: openWindow()}));
    o.fire('bus.orkestera', '.bus.state', {});

    o.component.destroy();

    assert.equal(o.stopped.length, 8);
    assert.deepEqual(o.timers(), []);
    assert.equal(o.hasTicker(), false);
});
