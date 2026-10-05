// The on-stream Chat Control Bus overlay (#138), on the pattern of
// live-overlay.js. Its root element carries:
//   data-live="on|off"       "off" once the overlay's token was rotated
//   data-bus-games='[...]'   every configured game; each has a bus.{game} channel
//   data-snapshot='{...}'    what the server rendered (BusOverlay::snapshot)
//
// The snapshot has no user data and no free text before a moderator approves
// it; the server decides that. This script only ever shows what a snapshot
// carries, and writes only numbers (counts, bar widths, the countdown).
//
// - bus.tally (coalesced by the server to about one a second): if only the
//   counts or the time left changed, write them in place. If anything else
//   changed (an option appeared or was vetoed, the window closed, a winner
//   awaits approval, a result, pause, kill or mode), re-render from the server.
//   A tally older than the version shown is dropped.
// - bus.state, bus.action, bus.veto: re-render.
// - The countdown runs here from the server's seconds_left, so the clock of
//   the machine running OBS doesn't matter. Once it reaches zero, and once a
//   result has been shown long enough, re-render.
//
// Fallback while the socket is down (no Reverb in production yet): re-render
// every 2-4 s. Only OBS sources run this overlay, so that is cheap, and a
// 60 s vote needs fresher counts than the question overlays. While connected,
// re-render every 60-90 s anyway, so a rotated token still blanks it. Every
// re-render re-checks the overlay token (PollingOverlay). One that comes back
// data-live="off" stops everything.
//
// Tests: resources/js/tests/live-bus.test.js (npm run test:js).

export const REFRESH_MIN_MS = 200;
export const REFRESH_JITTER_MS = 800;
export const POLL_MIN_MS = 2000;
export const POLL_JITTER_MS = 2000;
export const HEARTBEAT_MIN_MS = 60000;
export const HEARTBEAT_JITTER_MS = 30000;
export const TICK_MS = 250;
// After the countdown ends, give the server this long to resolve and tell us.
export const CLOSE_GRACE_MS = 2500;
const EVENTS = ['.bus.tally', '.bus.state', '.bus.action', '.bus.veto'];

/**
 * Everything about a snapshot except counts and time: if this differs, the
 * overlay needs the server to re-render it.
 */
export function shape(snapshot) {
    if (!snapshot) {
        return 'none';
    }

    const window = snapshot.window ?? null;

    return JSON.stringify({
        game: snapshot.game?.key ?? null,
        running: snapshot.running ?? null,
        killed: Boolean(snapshot.killed),
        paused: Boolean(snapshot.paused),
        mode: snapshot.mode ?? null,
        window: window && {
            id: window.id,
            options: (window.options ?? []).map((o) => [o.number, o.verb, o.label, Boolean(o.vetoed)]),
        },
        awaiting: snapshot.awaiting ?? null,
        result: snapshot.result ? [snapshot.result.status, snapshot.result.verb, snapshot.result.label] : null,
    });
}

export function clock(seconds) {
    const s = Math.max(0, Math.ceil(seconds));

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

export function percent(votes, total) {
    return Math.round((100 * votes) / Math.max(1, total));
}

function parse(json) {
    try {
        return json ? JSON.parse(json) : null;
    } catch {
        return null;
    }
}

/**
 * The Alpine component. `echo`, `random`, `now` and the timers are injectable
 * so the logic can be tested without a browser.
 */
export function liveBus({
    echo = () => globalThis.window?.Echo,
    random = Math.random,
    now = () => Date.now(),
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = (id) => clearTimeout(id),
    setTicker = (fn, ms) => setInterval(fn, ms),
    clearTicker = (id) => clearInterval(id),
} = {}) {
    return {
        channels: [],
        refreshTimer: null,
        pollTimer: null,
        ticker: null,
        polling: false,
        connected: false,
        hasConnected: false,
        stopped: false,
        stopWatching: null,
        snapshot: null,
        version: 0,
        closesAt: null,
        resultEndsAt: null,
        asked: false,

        init() {
            if (this.$root.dataset.live === 'off') {
                this.stopped = true;

                return;
            }

            this.read();
            this.ticker = setTicker(() => this.tick(), TICK_MS);

            const client = echo();
            if (!client) {
                this.startPolling();

                return;
            }

            for (const game of parse(this.$root.dataset.busGames) ?? []) {
                const channel = client.channel(`bus.${game}`)
                    .listen('.bus.tally', (payload) => this.tally(payload))
                    .listen('.bus.state', () => this.refreshSoon())
                    .listen('.bus.action', () => this.refreshSoon())
                    .listen('.bus.veto', () => this.refreshSoon());
                this.channels.push(channel);
            }

            const connector = client.connector;
            if (typeof connector?.onConnectionChange === 'function') {
                this.stopWatching = connector.onConnectionChange((status) => this.connectionChanged(status));
            }
            this.connectionChanged(client.connectionStatus?.() ?? 'disconnected');
        },

        destroy() {
            this.stopped = true;
            this.channels.forEach((channel) => EVENTS.forEach((name) => channel.stopListening(name)));
            this.channels = [];
            this.stopWatching?.();
            this.stopWatching = null;
            this.stopPolling();
            clearTimer(this.refreshTimer);
            this.refreshTimer = null;
            if (this.ticker !== null) {
                clearTicker(this.ticker);
                this.ticker = null;
            }
        },

        // Take in what the server just rendered.
        read() {
            this.snapshot = parse(this.$root.dataset.snapshot);
            this.version = Number(this.snapshot?.version ?? 0);
            const left = this.snapshot?.window?.seconds_left;
            this.closesAt = left === undefined || this.snapshot?.paused ? null : now() + Number(left) * 1000;
            const shown = this.snapshot?.result?.seconds_left;
            this.resultEndsAt = shown === undefined ? null : now() + Number(shown) * 1000;
            this.asked = false;
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

            if (reconnected) {
                this.refreshSoon();
            }
        },

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

        refresh() {
            return Promise.resolve(this.$wire.$refresh()).then(() => {
                if (this.$root.dataset.live === 'off') {
                    this.destroy();

                    return;
                }
                this.read();
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

        tally(payload) {
            if (this.stopped || !payload) {
                return;
            }

            const shownGame = this.snapshot?.game?.key ?? null;
            if ((payload.game?.key ?? null) !== shownGame) {
                // Another game: it matters only if it is now the one running,
                // or the kill switch changed for everyone.
                if (payload.running || Boolean(payload.killed) !== Boolean(this.snapshot?.killed)) {
                    this.refreshSoon();
                }

                return;
            }

            if (Number(payload.version) <= this.version) {
                return;
            }
            this.version = Number(payload.version);

            if (shape(payload) !== shape(this.snapshot)) {
                this.refreshSoon();

                return;
            }

            this.applyCounts(payload);
        },

        applyCounts(payload) {
            const window = payload.window;
            this.snapshot = payload;
            if (!window) {
                return;
            }

            for (const option of window.options) {
                const li = this.$root.querySelector(`[data-option="${option.number}"]`);
                if (!li || option.vetoed) {
                    continue;
                }
                const votes = li.querySelector('[data-option-votes]');
                if (votes) {
                    votes.textContent = String(option.votes);
                }
                const bar = li.querySelector('[data-option-bar]');
                if (bar) {
                    bar.style.width = `${percent(option.votes, window.total)}%`;
                }
            }

            if (!payload.paused && window.seconds_left !== undefined) {
                this.closesAt = now() + Number(window.seconds_left) * 1000;
                this.asked = false;
            }
        },

        tick() {
            if (this.stopped) {
                return;
            }

            if (this.closesAt !== null) {
                const left = (this.closesAt - now()) / 1000;
                const countdown = this.$root.querySelector('[data-countdown]');
                if (countdown) {
                    countdown.textContent = clock(left);
                }
                if (left * 1000 <= -CLOSE_GRACE_MS && !this.asked) {
                    this.asked = true;
                    this.refreshSoon();
                }
            }

            if (this.resultEndsAt !== null && now() >= this.resultEndsAt && !this.asked) {
                this.asked = true;
                this.refreshSoon();
            }
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('liveBus', () => liveBus());
    });
}
