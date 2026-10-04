// The vote page's link to the public `questions` channel: one subscription per
// viewer, handled here in the browser rather than by Livewire listeners, so a
// vote never makes every open page call the server.
//
// - VoteCast: write the new total into that question's cards (Top and New) and
//   re-sort Top Suggestions, straight from the payload. Events carry the
//   question's vote_version; one not newer than the version shown is late and
//   is dropped.
// - QuestionArchived: remove those questions' cards. Only "the whole queue"
//   (ids: null) needs a refresh.
// - QuestionSubmitted: refresh once, after a random delay so every viewer does
//   not hit the server in the same instant. Events inside that window share
//   the one refresh.
//
// Tests: resources/js/tests/live-queue.test.js (npm run test:js).

export const REFRESH_MIN_MS = 500;
export const REFRESH_JITTER_MS = 2500;
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
        refreshTimer: null,

        init() {
            const client = echo();
            if (!client) {
                return;
            }

            this.channel = client.channel('questions')
                .listen('VoteCast', (event) => this.voteCast(event))
                .listen('QuestionSubmitted', () => this.refreshSoon())
                .listen('QuestionArchived', (event) => this.archived(event));
        },

        destroy() {
            EVENTS.forEach((name) => this.channel?.stopListening(name));
            clearTimer(this.refreshTimer);
            this.refreshTimer = null;
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
                this.$wire.$refresh();
            }, REFRESH_MIN_MS + random() * REFRESH_JITTER_MS);
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('liveQueue', () => liveQueue());
    });
}
