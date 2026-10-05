// Run with: npm run test:js (node --test, no dependencies).
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {POLL_JITTER_MS, POLL_MIN_MS, REFRESH_JITTER_MS, REFRESH_MIN_MS, liveQueue} from '../live-queue.js';

// Just enough DOM for live-queue.js: attribute selectors, children, append()
// that moves nodes, remove() and lastElementChild.
class El {
    constructor(tag, dataset = {}, textContent = '') {
        this.tag = tag;
        this.dataset = {...dataset};
        this.text = String(textContent);
        this.children = [];
        this.parent = null;
    }

    // The DOM stores text as a string, whatever is assigned.
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

    get lastElementChild() {
        return this.children.at(-1) ?? null;
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

    setAttribute(name, value) {
        this.attributes = {...(this.attributes ?? {}), [name]: String(value)};
    }

    getAttribute(name) {
        return this.attributes?.[name] ?? null;
    }
}

// A card as resources/views/components/question-card.blade.php renders it:
// the count, and the two vote buttons tagged with their question (#180).
function card(id, votes, version = 0) {
    const button = (direction) => {
        const el = new El('button', {voteButton: direction, question: id});
        el.setAttribute('aria-pressed', 'false');

        return el;
    };

    return new El('li', {questionId: id}).add(new El('p', {voteCount: id, voteVersion: version}, String(votes)), button('up'), button('down'));
}

/**
 * A vote page with Top and New lists, a controllable clock and a fake Echo.
 * `status` is the socket's state at start (Echo's connectionStatus()), or
 * `null` for a build without Echo. `setStatus()` simulates the connector's
 * state changes.
 */
function page(top, recent = [], {status = 'connected'} = {}) {
    const topList = new El('ul').add(...top.map(([id, votes, version]) => card(id, votes, version)));
    const recentList = new El('ul').add(...recent.map(([id, votes, version]) => card(id, votes, version)));
    const root = new El('div').add(topList, recentList);

    let timers = [];
    let nextId = 0;
    const refreshes = [];
    const listeners = {};
    const stopped = [];
    const channel = {
        listen(name, handler) { listeners[name] = handler; return channel; },
        stopListening(name) { stopped.push(name); return channel; },
    };
    const subscribed = [];
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
    const setStatus = (next) => { current = next; watcher?.(next); };

    const component = liveQueue({
        echo: () => (status === null ? undefined : echo),
        random: () => 0.5,
        setTimer: (fn, ms) => { const id = ++nextId; timers.push({id, fn, ms}); return id; },
        clearTimer: (id) => { timers = timers.filter((t) => t.id !== id); },
    });
    // refreshQueue and dispatch() log into one list, so tests can check they go out together.
    const dispatches = [];
    const calls = [];
    Object.assign(component, {$root: root, $refs: {top: topList}, $wire: {
        refreshQueue: () => { refreshes.push(1); calls.push('refreshQueue'); },
        dispatch: (name) => { dispatches.push(name); calls.push(name); },
    }});
    component.init();

    const order = () => topList.children.map((li) => Number(li.dataset.questionId));
    const shown = (id) => root.querySelectorAll(`[data-vote-count="${id}"]`).map((el) => [el.textContent, Number(el.dataset.voteVersion)]);
    const fire = (name, payload) => listeners[name](payload);
    // Runs the timers due now; any they schedule wait for the next call.
    const runTimers = () => { const due = timers; timers = []; due.forEach((t) => t.fn()); };
    const pending = () => timers;
    const watching = () => watcher !== null;

    return {component, root, refreshes, dispatches, calls, subscribed, stopped, order, shown, fire, runTimers, pending, setStatus, watching};
}

test('subscribes once to the questions and topic channels and stops listening on destroy', () => {
    const p = page([]);

    assert.deepEqual(p.subscribed, ['questions', 'topic']);
    p.component.destroy();
    assert.deepEqual(p.stopped.sort(), ['QuestionArchived', 'QuestionSubmitted', 'TopicChanged', 'VoteCast']);
});

// Topic changes (#125): handled here rather than by a Livewire echo listener.

test('TopicChanged refreshes the page and syncs the topic component together, after the jitter', () => {
    const p = page([]);

    p.fire('TopicChanged', {topic: 'Kale'});
    assert.equal(p.refreshes.length, 0, 'not in the same instant for every viewer');
    assert.equal(p.pending().length, 1);

    p.runTimers();
    assert.deepEqual(p.calls, ['refreshQueue', 'topic-sync']);
});

test('a topic change shares the refresh of a new question, and only one topic-sync goes out', () => {
    const p = page([]);

    p.fire('QuestionSubmitted', {id: 9});
    p.fire('TopicChanged', {topic: 'Kale'});
    p.fire('TopicChanged', {topic: null});
    p.runTimers();

    assert.deepEqual(p.calls, ['refreshQueue', 'topic-sync']);
});

test('a refresh for questions alone does not touch the topic component', () => {
    const p = page([]);

    p.fire('QuestionSubmitted', {id: 9});
    p.runTimers();

    assert.deepEqual(p.calls, ['refreshQueue']);
});

test('without Echo every fallback poll also syncs the topic component', () => {
    const p = page([], [], {status: null});

    p.runTimers();
    p.runTimers();

    assert.deepEqual(p.calls, ['refreshQueue', 'topic-sync', 'refreshQueue', 'topic-sync']);
});

test('reconnecting syncs the topic too, since a TopicChanged may have been missed', () => {
    const p = page([]);
    p.setStatus('unavailable');
    p.runTimers();
    p.calls.length = 0;

    p.setStatus('connected');
    p.runTimers();

    assert.deepEqual(p.calls, ['refreshQueue', 'topic-sync']);
});

test('without Echo (no Reverb key in the build) it subscribes to nothing', () => {
    const p = page([], [], {status: null});

    assert.deepEqual(p.subscribed, []);
    assert.equal(p.component.channel, null);
});

// The viewer's own vote (#180): the page's upvote/downvote are renderless and
// answer with vote-recorded instead of re-rendering 100 cards.

const pressed = (p, id) => p.root.querySelectorAll(`[data-question="${id}"]`).map((b) => `${b.dataset.voteButton}:${b.getAttribute('aria-pressed')}`);

test('vote-recorded writes the total and presses the viewer\'s button in both lists, with no request', () => {
    const p = page([[1, 5, 3], [2, 1, 0]], [[2, 1, 0], [1, 5, 3]]);

    p.component.recorded({question_id: 2, votes: 7, version: 1, vote: 1});

    assert.deepEqual(p.shown(2), [['7', 1], ['7', 1]]);
    assert.deepEqual(pressed(p, 2), ['up:true', 'down:false', 'up:true', 'down:false']);
    assert.deepEqual(pressed(p, 1), ['up:false', 'down:false', 'up:false', 'down:false'], 'other questions untouched');
    assert.deepEqual(p.order(), [2, 1], 'Top re-sorts as for a VoteCast');
    assert.equal(p.refreshes.length, 0);
});

test('vote-recorded switches a pressed upvote to a downvote', () => {
    const p = page([[1, 5, 3]]);

    p.component.recorded({question_id: 1, votes: 6, version: 4, vote: 1});
    p.component.recorded({question_id: 1, votes: 4, version: 5, vote: -1});

    assert.deepEqual(pressed(p, 1), ['up:false', 'down:true']);
    assert.deepEqual(p.shown(1), [['4', 5]]);
});

test('vote-recorded and VoteCast for the same vote apply once, in either order', () => {
    const early = page([[1, 5, 3]]);
    early.fire('VoteCast', {question_id: 1, votes: 6, version: 4});
    early.component.recorded({question_id: 1, votes: 6, version: 4, vote: 1});
    assert.deepEqual(early.shown(1), [['6', 4]]);
    assert.deepEqual(pressed(early, 1), ['up:true', 'down:false'], 'the button is pressed even when the broadcast won');

    // A newer vote by someone else arrived first: the viewer's older total is dropped.
    const late = page([[1, 5, 3]]);
    late.fire('VoteCast', {question_id: 1, votes: 9, version: 6});
    late.component.recorded({question_id: 1, votes: 6, version: 4, vote: 1});
    assert.deepEqual(late.shown(1), [['9', 6]]);
    assert.deepEqual(pressed(late, 1), ['up:true', 'down:false']);
});

test('VoteCast writes the total and version into every card for that question, with no request', () => {
    const p = page([[1, 5, 3], [2, 1, 0]], [[2, 1, 0], [1, 5, 3]]);

    p.fire('VoteCast', {question_id: 2, votes: 4, version: 1});

    assert.deepEqual(p.shown(2), [['4', 1], ['4', 1]]);
    assert.deepEqual(p.shown(1), [['5', 3], ['5', 3]]);
    assert.equal(p.pending().length, 0);
    assert.equal(p.refreshes.length, 0);
});

test('a VoteCast not newer than the version shown is dropped', () => {
    const p = page([[1, 6, 5]]);

    p.fire('VoteCast', {question_id: 1, votes: 4, version: 4});
    p.fire('VoteCast', {question_id: 1, votes: 9, version: 5});
    assert.deepEqual(p.shown(1), [['6', 5]]);

    p.fire('VoteCast', {question_id: 1, votes: 7, version: 6});
    assert.deepEqual(p.shown(1), [['7', 6]]);
});

test('Top Suggestions re-sorts by votes, keeping tied questions in their current order', () => {
    const p = page([[1, 5], [2, 3], [3, 3], [4, 1]]);

    p.fire('VoteCast', {question_id: 4, votes: 6, version: 1});
    assert.deepEqual(p.order(), [4, 1, 2, 3]);

    p.fire('VoteCast', {question_id: 1, votes: 2, version: 1});
    assert.deepEqual(p.order(), [4, 2, 3, 1]);

    p.fire('VoteCast', {question_id: 1, votes: 3, version: 2});
    assert.deepEqual(p.order(), [4, 2, 3, 1], 'a new tie does not jump ahead');
});

test('a question outside Top that out-votes the last entry triggers one jittered refresh', () => {
    const p = page([[1, 5], [2, 3]], [[9, 2]]);

    p.fire('VoteCast', {question_id: 9, votes: 3, version: 1});
    assert.equal(p.pending().length, 0, 'a tie with the last entry does not refresh');

    p.fire('VoteCast', {question_id: 9, votes: 4, version: 2});
    assert.equal(p.pending().length, 1);

    p.runTimers();
    assert.equal(p.refreshes.length, 1);
});

test('QuestionArchived removes those cards from both lists without a request', () => {
    const p = page([[1, 5], [2, 3], [3, 1]], [[3, 1], [2, 3]]);

    p.fire('QuestionArchived', {ids: [2, 3]});

    assert.deepEqual(p.order(), [1]);
    assert.deepEqual(p.shown(2), []);
    assert.deepEqual(p.shown(3), []);
    assert.equal(p.pending().length, 0);
});

test('archiving the whole queue (ids: null) refreshes instead', () => {
    const p = page([[1, 5]]);

    p.fire('QuestionArchived', {ids: null});
    p.runTimers();

    assert.equal(p.refreshes.length, 1);
});

test('new questions share one refresh after a random 0.5–3 s delay', () => {
    const p = page([]);

    p.fire('QuestionSubmitted', {id: 7});
    p.fire('QuestionSubmitted', {id: 8});
    assert.equal(p.pending().length, 1);
    assert.equal(p.pending()[0].ms, REFRESH_MIN_MS + 0.5 * REFRESH_JITTER_MS);

    p.runTimers();
    p.fire('QuestionSubmitted', {id: 9});
    p.runTimers();
    assert.equal(p.refreshes.length, 2, 'a new window opens once the refresh has run');
});

test('the delay spans 0.5 s to 3 s', () => {
    const delays = [0, 0.999999].map((r) => {
        let ms;
        const component = liveQueue({random: () => r, setTimer: (fn, delay) => { ms = delay; return 1; }});
        component.refreshSoon();

        return ms;
    });

    assert.equal(delays[0], 500);
    assert.ok(delays[1] < 3000 && delays[1] > 2999);
});

// --- Fallback polling while the socket is not connected ---------------------

const POLL_MS = POLL_MIN_MS + 0.5 * POLL_JITTER_MS;

test('without Echo (no Reverb key in the build) it polls every 5–10 s, like wire:poll did', () => {
    const p = page([[1, 5]], [], {status: null});

    assert.equal(p.pending().length, 1);
    assert.equal(p.pending()[0].ms, POLL_MS);

    p.runTimers();
    p.runTimers();
    assert.equal(p.refreshes.length, 2, 'each poll refreshes and schedules the next');
    assert.equal(p.pending().length, 1);
});

test('the poll delay spans 5 s to 10 s', () => {
    const delays = [0, 0.999999].map((r) => {
        let ms;
        liveQueue({echo: () => undefined, random: () => r, setTimer: (fn, delay) => { ms = delay; return 1; }}).init();

        return ms;
    });

    assert.equal(delays[0], 5000);
    assert.ok(delays[1] < 10000 && delays[1] > 9999);
});

test('with the socket connected it does not poll', () => {
    const p = page([[1, 5]]);

    assert.ok(p.watching());
    assert.equal(p.pending().length, 0);
    p.runTimers();
    assert.equal(p.refreshes.length, 0);
});

test('it polls while the socket is still connecting, and stops once it connects', () => {
    const p = page([[1, 5]], [], {status: 'connecting'});
    assert.equal(p.pending().length, 1);

    p.setStatus('connected');
    assert.equal(p.pending().length, 0);
    p.runTimers();
    assert.equal(p.refreshes.length, 0, 'a first connection has nothing to catch up on');
});

test('a dropped socket polls until it reconnects, then refreshes once to catch up', () => {
    const p = page([[1, 5]]);

    p.setStatus('disconnected');
    assert.equal(p.pending().length, 1);
    p.runTimers();
    assert.equal(p.refreshes.length, 1);

    p.setStatus('connected');
    assert.equal(p.pending().length, 1, 'only the catch-up refresh is scheduled');
    assert.equal(p.pending()[0].ms, REFRESH_MIN_MS + 0.5 * REFRESH_JITTER_MS);
    p.runTimers();
    assert.equal(p.refreshes.length, 2);

    p.runTimers();
    assert.equal(p.refreshes.length, 2, 'polling has stopped');
});

test('a failed socket keeps polling, and repeated status changes never stack polls', () => {
    const p = page([[1, 5]]);

    p.setStatus('failed');
    p.setStatus('disconnected');
    p.setStatus('connecting');
    assert.equal(p.pending().length, 1);
});

test('destroy stops polling and watching the connection', () => {
    const p = page([[1, 5]], [], {status: 'disconnected'});

    p.component.destroy();
    assert.equal(p.pending().length, 0);
    assert.equal(p.watching(), false);
});
