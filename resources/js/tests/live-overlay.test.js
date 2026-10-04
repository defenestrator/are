// Run with: npm run test:js (node --test, no dependencies).
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    HEARTBEAT_JITTER_MS,
    HEARTBEAT_MIN_MS,
    POLL_JITTER_MS,
    POLL_MIN_MS,
    REFRESH_JITTER_MS,
    REFRESH_MIN_MS,
    liveOverlay,
} from '../live-overlay.js';

// Just enough DOM for live-overlay.js (the same approach as live-queue.test.js).
class El {
    constructor(tag, dataset = {}, textContent = '') {
        this.tag = tag;
        this.dataset = {...dataset};
        this.text = String(textContent);
        this.children = [];
        this.parent = null;
    }

    get textContent() {
        return this.text;
    }

    set textContent(value) {
        this.text = String(value);
    }

    add(...nodes) {
        this.append(...nodes);

        return this;
    }

    append(...nodes) {
        nodes.forEach((node) => {
            node.remove();
            node.parent = this;
            this.children.push(node);
        });
    }

    remove() {
        if (this.parent) {
            this.parent.children = this.parent.children.filter((child) => child !== this);
            this.parent = null;
        }
    }

    descendants() {
        return this.children.flatMap((child) => [child, ...child.descendants()]);
    }

    querySelectorAll(selector) {
        const [, tag, attr, value] = selector.match(/^(\w*)\[data-([\w-]+)(?:="([^"]*)")?\]$/);
        const key = attr.replace(/-(\w)/g, (_, c) => c.toUpperCase());

        return this.descendants().filter((el) => (!tag || el.tag === tag)
            && key in el.dataset
            && (value === undefined || String(el.dataset[key]) === value));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }
}

function card(id, votes, version, rank) {
    const li = new El('li', {questionId: String(id)});
    if (rank !== undefined) {
        li.add(new El('span', {rank: ''}, String(rank)));
    }

    return li.add(new El('span', {voteCount: String(id), voteVersion: String(version ?? 0)}, String(votes)));
}

/**
 * An overlay with a controllable clock and fake Echo. `rows` are
 * [id, votes, version]. `status` is the socket state at start, or null for a
 * build without Echo.
 */
function overlay(rows, {mode = 'top', limit = 3, status = 'connected', live = 'on', topic = false} = {}) {
    const list = new El('ol').add(...rows.map(([id, votes, version], i) => card(id, votes, version, mode === 'top' ? i + 1 : undefined)));
    const root = new El('div', {live, liveMode: mode, liveLimit: String(limit), ...(topic ? {liveTopic: 'on'} : {})}).add(list);

    let timers = [];
    let nextId = 0;
    let refreshes = 0;
    let afterRefresh = () => {};
    const listeners = {};
    const stopped = [];
    const subscribed = [];
    const channel = {
        listen(name, handler) { listeners[name] = handler; return channel; },
        stopListening(name) { stopped.push(name); return channel; },
    };
    let current = status;
    let watcher = null;
    const echo = {
        channel(name) { subscribed.push(name); return channel; },
        connectionStatus: () => current,
        connector: {
            onConnectionChange(callback) {
                watcher = callback;

                return () => { watcher = null; };
            },
        },
    };

    const component = liveOverlay({
        echo: () => (status === null ? undefined : echo),
        random: () => 0.5,
        setTimer: (fn, ms) => { const id = ++nextId; timers.push({id, fn, ms}); return id; },
        clearTimer: (id) => { timers = timers.filter((t) => t.id !== id); },
    });
    Object.assign(component, {
        $root: root,
        $refs: {list},
        $wire: {$refresh: () => { refreshes++; afterRefresh(); return Promise.resolve(); }},
    });
    component.init();

    return {
        component,
        root,
        subscribed,
        stopped,
        fire: (name, payload) => listeners[name](payload),
        setStatus: (next) => { current = next; watcher?.(next); },
        order: () => list.children.map((li) => Number(li.dataset.questionId)),
        ranks: () => list.children.map((li) => li.querySelector('[data-rank]')?.textContent),
        shown: (id) => root.querySelectorAll(`[data-vote-count="${id}"]`).map((el) => [el.textContent, Number(el.dataset.voteVersion)]),
        timers: () => timers.map((t) => t.ms),
        refreshes: () => refreshes,
        onRefresh: (fn) => { afterRefresh = fn; },
        // Run the earliest timer, as the clock reaching it would.
        async tick() {
            const next = [...timers].sort((a, b) => a.ms - b.ms)[0];
            timers = timers.filter((t) => t !== next);
            next.fn();
            await Promise.resolve();
            await Promise.resolve();
        },
        watching: () => watcher !== null,
    };
}

const REFRESH = REFRESH_MIN_MS + 0.5 * REFRESH_JITTER_MS;
const POLL = POLL_MIN_MS + 0.5 * POLL_JITTER_MS;
const HEARTBEAT = HEARTBEAT_MIN_MS + 0.5 * HEARTBEAT_JITTER_MS;

// Connection and fallback

test('without Echo it polls the server with jitter, as wire:poll did', async () => {
    const o = overlay([[1, 3]], {status: null});

    assert.deepEqual(o.subscribed, []);
    assert.deepEqual(o.timers(), [POLL]);

    await o.tick();
    assert.equal(o.refreshes(), 1);
    assert.deepEqual(o.timers(), [POLL], 'it keeps polling');
});

test('connected, it subscribes to the public questions channel and only keeps a slow heartbeat', async () => {
    const o = overlay([[1, 3]]);

    assert.deepEqual(o.subscribed, ['questions']);
    assert.deepEqual(o.timers(), [HEARTBEAT]);

    await o.tick();
    assert.equal(o.refreshes(), 1, 'the heartbeat re-renders so a rotated token is noticed');
    assert.deepEqual(o.timers(), [HEARTBEAT]);
});

test('a dropped socket falls back to polling, and reconnecting refreshes once and stops it', async () => {
    const o = overlay([[1, 3]]);

    o.setStatus('unavailable');
    assert.deepEqual(o.timers(), [POLL]);

    o.setStatus('connected');
    assert.deepEqual(o.timers().sort((a, b) => a - b), [REFRESH, HEARTBEAT]);
    await o.tick();
    assert.equal(o.refreshes(), 1, 'one catch-up refresh for the events missed while down');
});

test('starting disconnected polls, and the first connection does not refresh', () => {
    const o = overlay([[1, 3]], {status: 'connecting'});
    assert.deepEqual(o.timers(), [POLL]);

    o.setStatus('connected');
    assert.deepEqual(o.timers(), [HEARTBEAT]);
});

// Rotation: the server render comes back data-live="off"

test('an overlay rendered with data-live="off" does nothing', () => {
    const o = overlay([], {live: 'off'});

    assert.deepEqual(o.subscribed, []);
    assert.deepEqual(o.timers(), []);
});

test('a refresh that comes back data-live="off" unsubscribes and stops every timer', async () => {
    const o = overlay([[1, 3]]);
    o.onRefresh(() => { o.root.dataset.live = 'off'; });

    await o.tick();

    assert.deepEqual(o.stopped.sort(), ['QuestionArchived', 'QuestionSubmitted', 'VoteCast']);
    assert.deepEqual(o.timers(), []);
    assert.equal(o.watching(), false);

    o.setStatus('unavailable');
    assert.deepEqual(o.timers(), [], 'no polling after the token was rotated');
});

test('the polling fallback also stops when the token is rotated', async () => {
    const o = overlay([[1, 3]], {status: null});
    o.onRefresh(() => { o.root.dataset.live = 'off'; });

    await o.tick();

    assert.deepEqual(o.timers(), []);
});

// VoteCast

test('VoteCast writes a newer total and ignores a late one', () => {
    const o = overlay([[1, 3, 4]]);

    o.fire('VoteCast', {question_id: 1, votes: 5, version: 5});
    assert.deepEqual(o.shown(1), [['5', 5]]);

    o.fire('VoteCast', {question_id: 1, votes: 4, version: 4});
    assert.deepEqual(o.shown(1), [['5', 5]]);
});

test('top mode re-sorts and renumbers the ranks', () => {
    const o = overlay([[1, 5], [2, 4], [3, 3]]);

    o.fire('VoteCast', {question_id: 3, votes: 9, version: 1});

    assert.deepEqual(o.order(), [3, 1, 2]);
    assert.deepEqual(o.ranks(), ['1', '2', '3']);
    assert.deepEqual(o.timers(), [HEARTBEAT], 'no refresh needed');
});

test('top mode refreshes when a question outside the slice out-votes the last entry', () => {
    const o = overlay([[1, 5], [2, 4], [3, 3]]);

    o.fire('VoteCast', {question_id: 9, votes: 3, version: 1});
    assert.deepEqual(o.timers(), [HEARTBEAT], 'a tie does not displace the last entry');

    o.fire('VoteCast', {question_id: 9, votes: 4, version: 2});
    assert.deepEqual(o.timers().sort((a, b) => a - b), [REFRESH, HEARTBEAT]);
});

test('top mode refreshes for any vote outside a slice that has room', () => {
    const o = overlay([[1, 5]], {limit: 3});

    o.fire('VoteCast', {question_id: 9, votes: -1, version: 1});

    assert.ok(o.timers().includes(REFRESH));
});

test('top mode refreshes when the last entry of a full slice loses votes', () => {
    const o = overlay([[1, 5], [2, 4], [3, 3]]);

    o.fire('VoteCast', {question_id: 3, votes: 2, version: 1});

    assert.ok(o.timers().includes(REFRESH));
});

test('recent mode updates totals in place and never re-sorts or refreshes', () => {
    const o = overlay([[3, 0], [2, 1], [1, 7]], {mode: 'recent'});

    o.fire('VoteCast', {question_id: 3, votes: 10, version: 1});
    o.fire('VoteCast', {question_id: 99, votes: 50, version: 1});

    assert.deepEqual(o.order(), [3, 2, 1]);
    assert.deepEqual(o.shown(3), [['10', 1]]);
    assert.deepEqual(o.timers(), [HEARTBEAT]);
});

// QuestionArchived and QuestionSubmitted

test('QuestionArchived removes the cards at once, renumbers, and refreshes to backfill', () => {
    const o = overlay([[1, 5], [2, 4], [3, 3]]);

    o.fire('QuestionArchived', {ids: [1]});

    assert.deepEqual(o.order(), [2, 3]);
    assert.deepEqual(o.ranks(), ['1', '2']);
    assert.ok(o.timers().includes(REFRESH));
});

test('QuestionArchived for questions not shown changes nothing', () => {
    const o = overlay([[1, 5]]);

    o.fire('QuestionArchived', {ids: [42]});

    assert.deepEqual(o.timers(), [HEARTBEAT]);
});

test('QuestionArchived for the whole queue refreshes', () => {
    const o = overlay([[1, 5]], {mode: 'recent'});

    o.fire('QuestionArchived', {ids: null});

    assert.ok(o.timers().includes(REFRESH));
});

test('QuestionSubmitted refreshes the newest-first queue', () => {
    const o = overlay([[3, 0], [2, 1]], {mode: 'recent'});

    o.fire('QuestionSubmitted', {id: 4});

    assert.ok(o.timers().includes(REFRESH));
});

test('QuestionSubmitted refreshes a ranked slice only if a 0-vote question could enter it', () => {
    const full = overlay([[1, 5], [2, 4], [3, 1]]);
    full.fire('QuestionSubmitted', {id: 4});
    assert.deepEqual(full.timers(), [HEARTBEAT]);

    const roomy = overlay([[1, 5]], {limit: 3});
    roomy.fire('QuestionSubmitted', {id: 4});
    assert.ok(roomy.timers().includes(REFRESH));

    const zeroes = overlay([[1, 5], [2, 0], [3, 0]]);
    zeroes.fire('QuestionSubmitted', {id: 4});
    assert.ok(zeroes.timers().includes(REFRESH));

    const empty = overlay([], {limit: 1});
    empty.fire('QuestionSubmitted', {id: 4});
    assert.ok(empty.timers().includes(REFRESH));
});

test('a burst of events shares one jittered refresh', async () => {
    const o = overlay([[3, 0]], {mode: 'recent'});

    o.fire('QuestionSubmitted', {id: 4});
    o.fire('QuestionSubmitted', {id: 5});
    o.fire('QuestionArchived', {ids: null});

    assert.equal(o.timers().filter((ms) => ms === REFRESH).length, 1);
    await o.tick();
    assert.equal(o.refreshes(), 1);
});

// TopicChanged (#125): only overlays that show the topic follow it, here
// rather than through a Livewire echo listener.

test('an overlay that shows the topic refreshes on TopicChanged, after the jitter', () => {
    const o = overlay([[1, 5]], {topic: true});

    assert.deepEqual(o.subscribed, ['questions', 'topic']);

    o.fire('TopicChanged', {topic: 'Kale'});
    assert.ok(o.timers().includes(REFRESH));
    assert.equal(o.refreshes(), 0);
});

test('overlays that do not show the topic do not subscribe to it', () => {
    const o = overlay([[1, 5]]);

    assert.deepEqual(o.subscribed, ['questions']);
});

test('destroy also stops listening for TopicChanged', () => {
    const o = overlay([[1, 5]], {topic: true});

    o.component.destroy();

    assert.ok(o.stopped.includes('TopicChanged'));
});

test('without Echo a topic-showing overlay subscribes to nothing and polls', () => {
    const o = overlay([[1, 5]], {topic: true, status: null});

    assert.deepEqual(o.subscribed, []);
    assert.deepEqual(o.timers(), [POLL]);
});

test('destroy unsubscribes and clears its timers', () => {
    const o = overlay([[1, 5]]);
    o.fire('QuestionSubmitted', {id: 4});

    o.component.destroy();

    assert.equal(o.stopped.length, 3);
    assert.deepEqual(o.timers(), []);
});
