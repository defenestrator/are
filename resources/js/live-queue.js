// The vote page's link to the public `questions` channel: one subscription per
// viewer, handled here in the browser rather than by Livewire listeners, so a
// vote never makes every open page call the server.
//
// - VoteCast: write the new total into that question's cards (Top and New) and
//   re-sort Top Suggestions, straight from the payload. Events carry the
//   question's vote_version; one not newer than the version shown is late and
//   is dropped.
// - vote-recorded (the viewer's own vote, from the page's renderless upvote
//   and downvote, #180): the same write, plus their pressed button.
// - QuestionArchived: remove those questions' cards. Only "the whole queue"
//   (ids: null) needs a refresh.
// - QuestionSubmitted: refresh once, after a random delay so every viewer does
//   not hit the server in the same instant. Events inside that window share
//   the one refresh.
//
// - TopicChanged (public `topic` channel): refresh the page (the submit form
//   depends on the topic) and send `topic-sync` to the topic component, which
//   re-reads the topic. Both go out together in the one jittered refresh.
//   Livewire's own echo listeners are not used: Livewire logs "Laravel Echo
//   cannot be found" for each one on every load without Reverb (#125).
//
// Fallback: whenever the socket is not connected (no Echo because the build
// has no Reverb key, Reverb unreachable, or a dropped connection), refresh the
// page every 5–10 s with jitter, as wire:poll used to, and sync the topic
// component too. The refresh reads the shared cached queue, so it is cheap.
// Polling stops as soon as the socket connects, and a reconnect refreshes once,
// topic included, to catch up on what was missed.
//
// Tests: resources/js/tests/live-queue.test.js (npm run test:js).

export const REFRESH_MIN_MS = 500;
export const REFRESH_JITTER_MS = 2500;
export const POLL_MIN_MS = 5000;
export const POLL_JITTER_MS = 5000;
const EVENTS = ['VoteCast', 'QuestionSubmitted', 'QuestionArchived'];

/**
 * The Alpine component. `echo`, `random` and the timer functions are
 * injectable so the logic can be tested without a browser.
 */
export function liveQueue({
    echo = () => globalThis.window?.Echo,
    random = Math.random,
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = (id) => clearTimeout(id),
} = {}) {
    return {
        channel: null,
        topicChannel: null,
        topicPending: false,
        refreshTimer: null,
        pollTimer: null,
        polling: false,
        hasConnected: false,
        stopWatching: null,

        init() {
            const client = echo();
            if (!client) {
                this.startPolling();

                return;
            }

            this.channel = client.channel('questions')
                .listen('VoteCast', (event) => this.voteCast(event))
                .listen('QuestionSubmitted', () => this.refreshSoon())
                .listen('QuestionArchived', (event) => this.archived(event));
            this.topicChannel = client.channel('topic')
                .listen('TopicChanged', () => this.topicChanged());

            // Echo exposes the status; the connector reports changes to it.
            const connector = client.connector;
            if (typeof connector?.onConnectionChange === 'function') {
                this.stopWatching = connector.onConnectionChange((status) => this.connectionChanged(status));
            }
            this.connectionChanged(client.connectionStatus?.() ?? 'disconnected');
        },

        destroy() {
            EVENTS.forEach((name) => this.channel?.stopListening(name));
            this.topicChannel?.stopListening('TopicChanged');
            this.stopWatching?.();
            this.stopPolling();
            clearTimer(this.refreshTimer);
            this.refreshTimer = null;
        },

        connectionChanged(status) {
            if (status !== 'connected') {
                this.startPolling();

                return;
            }

            const reconnected = this.hasConnected && this.polling;
            this.hasConnected = true;
            this.stopPolling();

            // Events sent while the socket was down are gone; fetch the state.
            if (reconnected) {
                this.topicPending = true;
                this.refreshSoon();
            }
        },

        startPolling() {
            if (this.polling) {
                return;
            }

            this.polling = true;
            this.schedulePoll();
        },

        schedulePoll() {
            this.pollTimer = setTimer(() => {
                this.pollTimer = null;
                if (!this.polling) {
                    return;
                }
                this.refresh({topic: true});
                this.schedulePoll();
            }, POLL_MIN_MS + random() * POLL_JITTER_MS);
        },

        stopPolling() {
            this.polling = false;
            if (this.pollTimer) {
                clearTimer(this.pollTimer);
                this.pollTimer = null;
            }
        },

        // The viewer's own vote, answered by the vote page's renderless
        // upvote/downvote (#180): the same version-checked write as a
        // VoteCast, which may arrive before or after it, plus their pressed
        // button, which the cards style from aria-pressed.
        recorded({ question_id, votes, version, vote }) {
            const id = Number(question_id);

            this.$root.querySelectorAll(`[data-question="${id}"]`).forEach((button) => {
                const mine = (button.dataset.voteButton === 'up' && Number(vote) > 0)
                    || (button.dataset.voteButton === 'down' && Number(vote) < 0);
                button.setAttribute('aria-pressed', mine ? 'true' : 'false');
            });

            this.voteCast({ question_id: id, votes, version });
        },

        voteCast({ question_id, votes, version }) {
            const id = Number(question_id);

            this.$root.querySelectorAll(`[data-vote-count="${id}"]`).forEach((el) => {
                if (Number(version) <= Number(el.dataset.voteVersion ?? 0)) {
                    return;
                }
                el.textContent = votes;
                el.dataset.voteVersion = version;
            });

            this.resortTop();

            // A question outside Top Suggestions that now out-votes its last
            // entry belongs in the list; only the server knows the rest of it.
            const top = this.$refs.top;
            if (top && !top.querySelector(`[data-question-id="${id}"]`)) {
                const last = top.lastElementChild;
                if (last && Number(votes) > this.votesOf(last)) {
                    this.refreshSoon();
                }
            }
        },

        archived({ ids }) {
            if (ids === null) {
                this.refreshSoon();

                return;
            }

            ids.map(Number).forEach((id) => {
                this.$root.querySelectorAll(`li[data-question-id="${id}"]`).forEach((li) => li.remove());
            });
        },

        votesOf(li) {
            return Number(li.querySelector('[data-vote-count]')?.textContent ?? 0);
        },

        // Stable sort, so tied questions keep their current order on the page.
        resortTop() {
            const list = this.$refs.top;
            if (!list) {
                return;
            }

            const items = [...list.children];
            const sorted = [...items].sort((a, b) => this.votesOf(b) - this.votesOf(a));

            if (sorted.some((li, i) => li !== items[i])) {
                list.append(...sorted);
            }
        },

        refreshSoon() {
            if (this.refreshTimer) {
                return;
            }

            this.refreshTimer = setTimer(() => {
                this.refreshTimer = null;
                this.refresh();
            }, REFRESH_MIN_MS + random() * REFRESH_JITTER_MS);
        },

        topicChanged() {
            this.topicPending = true;
            this.refreshSoon();
        },

        // Called in one tick, so Livewire sends both in one request.
        // refreshQueue (not $refresh) renders only if the queue, topic or the
        // viewer's form changed since the last render: the cards are Blade,
        // so a needless render would cost all of them (#180).
        refresh({topic = false} = {}) {
            this.$wire.refreshQueue();
            if (topic || this.topicPending) {
                this.topicPending = false;
                this.$wire.dispatch('topic-sync');
            }
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('liveQueue', () => liveQueue());
    });
}
