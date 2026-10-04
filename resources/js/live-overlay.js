// The OBS overlays' link to the public `questions` channel (#22), on the same
// pattern as the vote page's resources/js/live-queue.js.
//
// Each overlay is a short slice of the queue, set by its root element:
//   data-live-mode="top"    ranked by votes (vote, top-vote), re-sorted here
//   data-live-mode="recent" newest first (queue)
//   data-live-limit="5"     how many it shows
//   data-live="on|off"      "off" once the overlay's token was rotated
//   data-live-topic="on"    it shows the topic (vote): refresh on TopicChanged
//
// - VoteCast: write the total into that question's card when the event's
//   vote_version is newer than the one shown. Top mode re-sorts and renumbers
//   the ranks. It refreshes when only the server can know the slice: a
//   question outside it now out-votes the last entry, the list isn't full, or
//   the last entry lost votes.
// - QuestionArchived: remove those cards at once, then refresh once to
//   backfill the slice. ids: null (the whole queue) just refreshes.
// - QuestionSubmitted: refresh, unless top mode is full of questions with
//   votes, where a new question (0 votes) can't enter.
// - TopicChanged (public `topic` channel, only with data-live-topic="on"):
//   refresh. Livewire's own echo listeners are not used: Livewire logs
//   "Laravel Echo cannot be found" for each one on every load without Reverb,
//   which OBS writes to its log (#125). Without a socket, the polling
//   fallback picks up a topic change like any other.
// Every refresh waits a random 0.5-3 s, and refreshes inside that window
// share one.
//
// The payloads are public-safe: ids, totals and versions only, never voters
// or question text. Overlay tokens don't gate the socket. They gate the page,
// and every server render re-checks the token (App\Livewire\Overlays\
// PollingOverlay), so a rotated token still blanks the overlay:
// - while the socket is down (no Reverb in this build, unreachable, or
//   dropped), poll with $refresh every 5-10 s, as wire:poll used to;
// - while it is up, refresh every 60-90 s anyway, so a rotation is noticed
//   without any events.
// A render that comes back data-live="off" stops everything.
//
// Tests: resources/js/tests/live-overlay.test.js (npm run test:js).

export const REFRESH_MIN_MS = 500;
export const REFRESH_JITTER_MS = 2500;
export const POLL_MIN_MS = 5000;
export const POLL_JITTER_MS = 5000;
export const HEARTBEAT_MIN_MS = 60000;
export const HEARTBEAT_JITTER_MS = 30000;
const EVENTS = ['VoteCast', 'QuestionSubmitted', 'QuestionArchived'];

/**
 * The Alpine component. `echo`, `random` and the timer functions are
 * injectable so the logic can be tested without a browser.
 */
export function liveOverlay({
    echo = () => globalThis.window?.Echo,
    random = Math.random,
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = (id) => clearTimeout(id),
} = {}) {
    return {
        channel: null,
        topicChannel: null,
        refreshTimer: null,
        pollTimer: null,
        polling: false,
        connected: false,
        hasConnected: false,
        stopped: false,
        stopWatching: null,

        get mode() {
            return this.$root.dataset.liveMode === 'recent' ? 'recent' : 'top';
        },

        get limit() {
            return Math.max(1, Number(this.$root.dataset.liveLimit) || 1);
        },

        get list() {
            return this.$refs.list ?? null;
        },

        init() {
            if (this.$root.dataset.live === 'off') {
                this.stopped = true;

                return;
            }

            const client = echo();
            if (!client) {
                this.startPolling();

                return;
            }

            this.channel = client.channel('questions')
                .listen('VoteCast', (event) => this.voteCast(event))
                .listen('QuestionSubmitted', () => this.submitted())
                .listen('QuestionArchived', (event) => this.archived(event));

            if (this.$root.dataset.liveTopic === 'on') {
                this.topicChannel = client.channel('topic')
                    .listen('TopicChanged', () => this.refreshSoon());
            }

            const connector = client.connector;
            if (typeof connector?.onConnectionChange === 'function') {
                this.stopWatching = connector.onConnectionChange((status) => this.connectionChanged(status));
            }
            this.connectionChanged(client.connectionStatus?.() ?? 'disconnected');
        },

        destroy() {
            this.stopped = true;
            EVENTS.forEach((name) => this.channel?.stopListening(name));
            this.channel = null;
            this.topicChannel?.stopListening('TopicChanged');
            this.topicChannel = null;
            this.stopWatching?.();
            this.stopWatching = null;
            this.stopPolling();
            clearTimer(this.refreshTimer);
            this.refreshTimer = null;
        },

        connectionChanged(status) {
            if (this.stopped) {
                return;
            }

            if (status !== 'connected') {
                this.connected = false;
                this.startPolling();

                return;
            }

            const reconnected = this.hasConnected && !this.connected;
            this.connected = true;
            this.hasConnected = true;
            this.stopPolling();
            this.schedule(HEARTBEAT_MIN_MS, HEARTBEAT_JITTER_MS);

            // Events sent while the socket was down are gone; fetch the state.
            if (reconnected) {
                this.refreshSoon();
            }
        },

        // One timer drives both the fallback poll and the connected heartbeat.
        schedule(min, jitter) {
            if (this.pollTimer) {
                clearTimer(this.pollTimer);
            }

            this.pollTimer = setTimer(() => {
                this.pollTimer = null;
                if (this.stopped) {
                    return;
                }
                this.refresh();
                if (this.polling) {
                    this.schedule(POLL_MIN_MS, POLL_JITTER_MS);
                } else if (this.connected) {
                    this.schedule(HEARTBEAT_MIN_MS, HEARTBEAT_JITTER_MS);
                }
            }, min + random() * jitter);
        },

        startPolling() {
            if (this.polling) {
                return;
            }

            this.polling = true;
            this.schedule(POLL_MIN_MS, POLL_JITTER_MS);
        },

        stopPolling() {
            this.polling = false;
            if (this.pollTimer) {
                clearTimer(this.pollTimer);
                this.pollTimer = null;
            }
        },

        // A server render; it re-checks the overlay token.
        refresh() {
            return Promise.resolve(this.$wire.$refresh()).then(() => {
                if (this.$root.dataset.live === 'off') {
                    this.destroy();
                }
            });
        },

        refreshSoon() {
            if (this.refreshTimer || this.stopped) {
                return;
            }

            this.refreshTimer = setTimer(() => {
                this.refreshTimer = null;
                if (!this.stopped) {
                    this.refresh();
                }
            }, REFRESH_MIN_MS + random() * REFRESH_JITTER_MS);
        },

        items() {
            return this.list ? [...this.list.children] : [];
        },

        votesOf(li) {
            return Number(li.querySelector('[data-vote-count]')?.textContent ?? 0);
        },

        voteCast({question_id, votes, version}) {
            const id = Number(question_id);
            let shown = false;
            let fell = false;

            this.$root.querySelectorAll(`[data-vote-count="${id}"]`).forEach((el) => {
                shown = true;
                if (Number(version) <= Number(el.dataset.voteVersion ?? 0)) {
                    return;
                }
                fell = fell || Number(votes) < Number(el.textContent);
                el.textContent = votes;
                el.dataset.voteVersion = version;
            });

            if (this.mode !== 'top') {
                return;
            }

            this.resort();

            const items = this.items();
            const last = items.at(-1);

            if (!shown) {
                // Outside the slice: it belongs in it if there's room, or if it
                // now out-votes the last entry.
                if (items.length < this.limit || (last && Number(votes) > this.votesOf(last))) {
                    this.refreshSoon();
                }
            } else if (fell && items.length >= this.limit && last?.dataset.questionId === String(id)) {
                // The last entry lost votes; a question outside may now beat it.
                this.refreshSoon();
            }
        },

        archived({ids}) {
            if (ids === null || ids === undefined) {
                this.refreshSoon();

                return;
            }

            let removed = false;
            ids.map(Number).forEach((id) => {
                this.$root.querySelectorAll(`li[data-question-id="${id}"]`).forEach((li) => {
                    li.remove();
                    removed = true;
                });
            });

            if (removed) {
                this.renumber();
                this.refreshSoon();
            }
        },

        submitted() {
            if (this.mode === 'recent') {
                this.refreshSoon();

                return;
            }

            // A new question has no votes, so it can only enter a ranked slice
            // that has room, or whose last entry has no votes either.
            const items = this.items();
            const last = items.at(-1);
            if (items.length < this.limit || !last || this.votesOf(last) <= 0) {
                this.refreshSoon();
            }
        },

        // Stable sort, so tied questions keep their current order.
        resort() {
            const list = this.list;
            if (!list) {
                return;
            }

            const items = [...list.children];
            const sorted = [...items].sort((a, b) => this.votesOf(b) - this.votesOf(a));

            if (sorted.some((li, i) => li !== items[i])) {
                list.append(...sorted);
            }

            this.renumber();
        },

        renumber() {
            this.items().forEach((li, index) => {
                const rank = li.querySelector('[data-rank]');
                if (rank) {
                    rank.textContent = index + 1;
                }
            });
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('liveOverlay', () => liveOverlay());
    });
}
