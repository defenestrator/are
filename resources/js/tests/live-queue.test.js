// Run with: npm run test:js (node --test, no dependencies).
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {REFRESH_JITTER_MS, REFRESH_MIN_MS, liveQueue} from '../live-queue.js';

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
}

function card(id, votes, version = 0) {
    return new El('li', {questionId: id}).add(new El('p', {voteCount: id, voteVersion: version}, String(votes)));
}

/** A vote page with Top and New lists, and a controllable clock. */
function page(top, recent = []) {
    const topList = new El('ul').add(...top.map(([id, votes, version]) => card(id, votes, version)));
    const recentList = new El('ul').add(...recent.map(([id, votes, version]) => card(id, votes, version)));
    const root = new El('div').add(topList, recentList);

    const timers = [];
    const refreshes = [];
    const listeners = {};
    const stopped = [];
    const channel = {
        listen(name, handler) { listeners[name] = handler; return channel; },
        stopListening(name) { stopped.push(name); return channel; },
    };
    const subscribed = [];
    const echo = {channel(name) { subscribed.push(name); return channel; }};

    const component = liveQueue({
        echo: () => echo,
        random: () => 0.5,
        setTimer: (fn, ms) => { timers.push({fn, ms}); return timers.length; },
        clearTimer: () => {},
    });
    Object.assign(component, {$root: root, $refs: {top: topList}, $wire: {$refresh: () => refreshes.push(1)}});
    component.init();

    const order = () => topList.children.map((li) => Number(li.dataset.questionId));
    const shown = (id) => root.querySelectorAll(`[data-vote-count="${id}"]`).map((el) => [el.textContent, Number(el.dataset.voteVersion)]);
    const fire = (name, payload) => listeners[name](payload);
    const runTimers = () => timers.splice(0).forEach((t) => t.fn());

    return {component, root, timers, refreshes, subscribed, stopped, order, shown, fire, runTimers};
}

test('subscribes once to the questions channel and stops listening on destroy', () => {
    const p = page([]);

    assert.deepEqual(p.subscribed, ['questions']);
    p.component.destroy();
    assert.deepEqual(p.stopped.sort(), ['QuestionArchived', 'QuestionSubmitted', 'VoteCast']);
});

test('does nothing without Echo (no Reverb key in the build)', () => {
    const component = liveQueue({echo: () => undefined});

    assert.doesNotThrow(() => component.init());
    assert.equal(component.channel, null);
});

test('VoteCast writes the total and version into every card for that question, with no request', () => {
    const p = page([[1, 5, 3], [2, 1, 0]], [[2, 1, 0], [1, 5, 3]]);

    p.fire('VoteCast', {question_id: 2, votes: 4, version: 1});

    assert.deepEqual(p.shown(2), [['4', 1], ['4', 1]]);
    assert.deepEqual(p.shown(1), [['5', 3], ['5', 3]]);
    assert.equal(p.timers.length, 0);
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
    assert.equal(p.timers.length, 0, 'a tie with the last entry does not refresh');

    p.fire('VoteCast', {question_id: 9, votes: 4, version: 2});
    assert.equal(p.timers.length, 1);

    p.runTimers();
    assert.equal(p.refreshes.length, 1);
});

test('QuestionArchived removes those cards from both lists without a request', () => {
    const p = page([[1, 5], [2, 3], [3, 1]], [[3, 1], [2, 3]]);

    p.fire('QuestionArchived', {ids: [2, 3]});

    assert.deepEqual(p.order(), [1]);
    assert.deepEqual(p.shown(2), []);
    assert.deepEqual(p.shown(3), []);
    assert.equal(p.timers.length, 0);
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
    assert.equal(p.timers.length, 1);
    assert.equal(p.timers[0].ms, REFRESH_MIN_MS + 0.5 * REFRESH_JITTER_MS);

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
