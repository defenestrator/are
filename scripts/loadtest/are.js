// ARE load test (#176). Run from a machine that is NOT the server under test:
// a load generator on the same box competes with PHP-FPM and flatters nothing.
//
//   k6 run -e BASE_URL=https://staging.example scripts/loadtest/are.js
//
// Profiles (PROFILE=):
//   ci               (default) every scenario, against a CI or staging instance
//                    seeded with LoadtestSeeder. Needs the test-only login,
//                    which exists only with APP_ENV=testing.
//   production-safe  read-only anonymous GETs at a low rate. The only profile
//                    ever to point at production. See README.md.
//
// Scenarios (SCENARIOS=a,b to run a subset):
//   anonymous  public pages (/, /about, /music, /up) at ANON_RATE requests/s
//   overlays   OVERLAY_SOURCES OBS sources, each polling its overlay the way
//              the page does without a socket: a Livewire $refresh every
//              5-10 s (now-playing: every 5 s)
//   vote       VIEWERS signed-in viewers on /vote, each polling every 5-10 s
//              like the page's fallback, and now and then voting
//   chat       a burst of signed EventSub channel.chat.message webhooks
//              carrying !vote and !q, up to CHAT_RATE messages/s
//
// Output: p50, p95 and p99 latency and the error rate per scenario, on stdout
// and in SUMMARY_PATH (Markdown). Thresholds fail the run (exit code 99).
import http from 'k6/http';
import crypto from 'k6/crypto';
import exec from 'k6/execution';
import { check, sleep } from 'k6';
import { readPage, updateBody, nextSnapshot, UPDATE_HEADERS } from './livewire.js';

const BASE_URL = (__ENV.BASE_URL || '').replace(/\/+$/, '');
const PROFILE = __ENV.PROFILE || 'ci';
const DURATION = __ENV.DURATION || (PROFILE === 'production-safe' ? '2m' : '60s');

if (!BASE_URL) {
    throw new Error('Set BASE_URL, for example -e BASE_URL=https://staging.example');
}
if (!['ci', 'production-safe'].includes(PROFILE)) {
    throw new Error(`Unknown PROFILE ${PROFILE}: use ci or production-safe.`);
}

const ALL = PROFILE === 'production-safe' ? ['anonymous'] : ['anonymous', 'overlays', 'vote', 'chat'];
const SELECTED = __ENV.SCENARIOS ? __ENV.SCENARIOS.split(',').map((s) => s.trim()).filter(Boolean) : ALL;
const refused = SELECTED.filter((name) => !ALL.includes(name));
if (refused.length > 0) {
    throw new Error(`Scenario(s) ${refused.join(', ')} are not allowed with PROFILE=${PROFILE}.`
        + (PROFILE === 'production-safe' ? ' Production gets read-only anonymous GETs only.' : ''));
}

// production-safe is capped: at most 3 requests a second, whatever ANON_RATE says.
const ANON_RATE = PROFILE === 'production-safe'
    ? Math.min(3, Number(__ENV.ANON_RATE || 1))
    : Number(__ENV.ANON_RATE || 20);
const OVERLAY_SOURCES = Number(__ENV.OVERLAY_SOURCES || 10);
const VIEWERS = Number(__ENV.VIEWERS || 50);
const CHAT_RATE = Number(__ENV.CHAT_RATE || 40);

// Only the write-capable profile needs the seeded fixtures.
const fixtures = PROFILE === 'ci' && SELECTED.some((name) => name !== 'anonymous')
    ? JSON.parse(open(__ENV.FIXTURES || './.fixtures.json'))
    : null;

const scenarios = {
    anonymous: {
        executor: 'constant-arrival-rate',
        exec: 'anonymous',
        rate: ANON_RATE,
        timeUnit: '1s',
        duration: DURATION,
        preAllocatedVUs: Math.max(2, ANON_RATE),
        maxVUs: Math.max(4, ANON_RATE * 4),
    },
    overlays: {
        executor: 'constant-vus',
        exec: 'overlay',
        vus: OVERLAY_SOURCES,
        duration: DURATION,
    },
    vote: {
        executor: 'constant-vus',
        exec: 'viewer',
        vus: VIEWERS,
        duration: DURATION,
    },
    chat: {
        executor: 'ramping-arrival-rate',
        exec: 'chat',
        startTime: '15s',
        startRate: 1,
        timeUnit: '1s',
        preAllocatedVUs: 20,
        maxVUs: 200,
        stages: [
            { target: CHAT_RATE, duration: '5s' },
            { target: CHAT_RATE, duration: '20s' },
            { target: 0, duration: '5s' },
        ],
    },
};

// Thresholds that fail the run. The ci numbers are a baseline for the CI job
// (php artisan serve with several workers on a shared runner), not production
// targets: they exist to catch regressions between runs.
const LIMITS = PROFILE === 'production-safe'
    ? { anonymous: { p95: 1000, p99: 2000 } }
    : {
        anonymous: { p95: 1000, p99: 2000 },
        overlays: { p95: 1500, p99: 3000 },
        vote: { p95: 2000, p99: 4000 },
        chat: { p95: 750, p99: 1500 },
    };

const thresholds = { checks: ['rate>0.99'] };
for (const name of SELECTED) {
    thresholds[`http_req_duration{scenario:${name}}`] = [`p(95)<${LIMITS[name].p95}`, `p(99)<${LIMITS[name].p99}`];
    thresholds[`http_req_failed{scenario:${name}}`] = ['rate<0.01'];
}

// A breakdown by request, so a slow scenario says which request is slow
// (a first page load or a poll). These never fail the run: k6 only reports
// a tagged sub-metric that has a threshold, so each gets one that always holds.
const BREAKDOWN = {
    anonymous: ['GET /', 'GET /about', 'GET /music', 'GET /up'],
    overlays: ['POST /overlay/{overlay}/session', 'GET /overlay/{overlay}', 'POST livewire/update (overlay refresh)'],
    vote: ['GET /_e2e/login -> /vote', 'POST livewire/update (vote $refresh)', 'POST livewire/update (vote card)'],
    chat: ['POST /twitch/eventsub (chat)'],
};
const BREAKDOWN_NAMES = SELECTED.reduce((names, scenario) => names.concat(BREAKDOWN[scenario]), []);
for (const name of BREAKDOWN_NAMES) {
    thresholds[`http_req_duration{name:${name}}`] = ['max>=0'];
}

export const options = {
    scenarios: Object.fromEntries(SELECTED.map((name) => [name, scenarios[name]])),
    thresholds,
    summaryTrendStats: ['p(50)', 'p(95)', 'p(99)', 'avg', 'max', 'count'],
    // Never follow a redirect off this host.
    maxRedirects: 3,
    // Each virtual user is one browser: keep its session across iterations.
    // k6 empties the cookie jar between iterations by default, which turned
    // every poll after the first into a 419.
    noCookiesReset: true,
    userAgent: 'ARE-loadtest/1 (k6; #176)',
};

const pick = (list) => list[Math.floor(Math.random() * list.length)];

// Report the first failed request of each virtual user, with its status, so
// a broken run says why. Bodies are not printed: they can hold page data.
let reported = false;
function reportFailure(what, response) {
    if (!reported && response.status !== 200 && response.status !== 204) {
        reported = true;
        console.warn(`${exec.scenario.name} VU ${exec.vu.idInTest}: ${what} answered HTTP ${response.status}${response.error ? ` (${response.error})` : ''}`);
    }
}
const jitter = (min, max) => min + Math.random() * (max - min);

// --- anonymous ------------------------------------------------------------------

const PUBLIC_PAGES = ['/', '/about', '/music', '/up'];

export function anonymous() {
    const path = pick(PUBLIC_PAGES);
    const response = http.get(`${BASE_URL}${path}`, { tags: { name: `GET ${path}` } });
    check(response, { 'public page is 200': (r) => r.status === 200 });
    reportFailure(`GET ${path}`, response);
}

// --- overlays ------------------------------------------------------------------

// The overlays that poll while there is no socket, and how often.
const POLLING_OVERLAYS = [
    { name: 'queue', every: [5, 10] },
    { name: 'vote', every: [5, 10] },
    { name: 'top-vote', every: [5, 10] },
    { name: 'now-playing', every: [5, 5] },
    { name: 'bus', every: [5, 10] },
];

// Per virtual user: one OBS source.
let source = null;

function openOverlay() {
    const overlay = POLLING_OVERLAYS[(exec.vu.idInTest - 1) % POLLING_OVERLAYS.length];
    const token = fixtures.overlayTokens[overlay.name];

    // Trade the #token= fragment for a grant cookie, as the bootstrap page does.
    const exchanged = http.post(`${BASE_URL}/overlay/${overlay.name}/session`, JSON.stringify({ token }), {
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            Origin: BASE_URL,
            'Sec-Fetch-Site': 'same-origin',
        },
        tags: { name: 'POST /overlay/{overlay}/session' },
    });
    check(exchanged, { 'overlay token exchange is 204': (r) => r.status === 204 });

    const page = http.get(`${BASE_URL}/overlay/${overlay.name}?layout=horizontal`, { tags: { name: 'GET /overlay/{overlay}' } });
    const state = readPage(page.body || '');
    const component = state.components[0];
    check(page, { 'overlay page renders a Livewire component': () => page.status === 200 && component !== undefined });

    return component ? { overlay, csrf: state.csrf, updateUri: state.updateUri, snapshot: component.snapshot } : null;
}

export function overlay() {
    if (source === null) {
        source = openOverlay();
        if (source === null) {
            sleep(5);

            return;
        }
    }

    const response = http.post(`${BASE_URL}${source.updateUri}`, updateBody(source.csrf, source.snapshot), {
        headers: UPDATE_HEADERS,
        tags: { name: 'POST livewire/update (overlay refresh)', overlay: source.overlay.name },
    });
    const snapshot = nextSnapshot(response);
    check(response, { 'overlay refresh is 200 with a snapshot': (r) => r.status === 200 && snapshot !== null });
    reportFailure(`overlay ${source.overlay.name} refresh`, response);
    if (snapshot !== null) {
        source.snapshot = snapshot;
    }

    sleep(jitter(source.overlay.every[0], source.overlay.every[1]));
}

// --- vote ----------------------------------------------------------------------

// Per virtual user: one signed-in viewer's /vote page.
let viewerPage = null;

function openVotePage() {
    const userId = fixtures.viewers[(exec.vu.idInTest - 1) % fixtures.viewers.length];
    const page = http.get(`${BASE_URL}/_e2e/login/${userId}?to=/vote`, { tags: { name: 'GET /_e2e/login -> /vote' } });

    if (page.status === 404) {
        exec.test.abort('The test-only login route is missing: the vote scenario needs APP_ENV=testing (CI or staging). Never run it against production.');
    }

    const state = readPage(page.body || '');
    // The page component is an anonymous Volt fragment named
    // "volt-anonymous-fragment-<base64 of {name: vote, ...}>".
    const vote = state.components.find((component) => component.name === 'vote' || component.name.indexOf('volt-anonymous-fragment-') === 0)
        || state.components.find((component) => !['question-card', 'topic'].includes(component.name));
    const cards = state.components.filter((component) => component.name === 'question-card');
    check(page, { '/vote renders for the viewer': (r) => r.status === 200 && r.url.endsWith('/vote') && vote !== undefined });

    return vote ? { csrf: state.csrf, updateUri: state.updateUri, vote: vote.snapshot, cards } : null;
}

function livewireCall(snapshot, method, params, name) {
    return http.post(`${BASE_URL}${viewerPage.updateUri}`, updateBody(viewerPage.csrf, snapshot, method, params), {
        headers: UPDATE_HEADERS,
        tags: { name },
    });
}

export function viewer() {
    if (viewerPage === null) {
        viewerPage = openVotePage();
        if (viewerPage === null) {
            sleep(5);

            return;
        }
    }

    // The polling fallback: refresh the page component.
    const refreshed = livewireCall(viewerPage.vote, '$refresh', [], 'POST livewire/update (vote $refresh)');
    const snapshot = nextSnapshot(refreshed);
    check(refreshed, { 'vote refresh is 200 with a snapshot': (r) => r.status === 200 && snapshot !== null });
    reportFailure('vote refresh', refreshed);
    if (snapshot !== null) {
        viewerPage.vote = snapshot;
    }

    // Now and then, vote on a question, as a viewer clicking a card would.
    if (viewerPage.cards.length > 0 && Math.random() < 0.15) {
        const index = Math.floor(Math.random() * viewerPage.cards.length);
        const voted = livewireCall(viewerPage.cards[index].snapshot, Math.random() < 0.8 ? 'upvote' : 'downvote', [], 'POST livewire/update (vote card)');
        const cardSnapshot = nextSnapshot(voted);
        check(voted, { 'vote click is 200': (r) => r.status === 200 });
        reportFailure('vote click', voted);
        if (cardSnapshot !== null) {
            viewerPage.cards[index].snapshot = cardSnapshot;
        }
    }

    sleep(jitter(5, 10));
}

// --- chat ----------------------------------------------------------------------

const EVENTSUB_SECRET = __ENV.EVENTSUB_SECRET || '';

export function chat() {
    if (EVENTSUB_SECRET === '') {
        exec.test.abort('Set EVENTSUB_SECRET to the instance\'s TWITCH_HELIX_EVENTSUB_SECRET for the chat scenario.');
    }

    const chatter = pick(fixtures.chatters);
    const id = `loadtest-${exec.vu.idInTest}-${exec.scenario.iterationInTest}-${Date.now()}`;
    const text = Math.random() < 0.7
        ? `!vote ${pick(fixtures.questions)}`
        : `!q Load test question ${id}`;
    const timestamp = new Date().toISOString();
    const body = JSON.stringify({
        subscription: { type: 'channel.chat.message', version: '1' },
        event: {
            broadcaster_user_id: fixtures.broadcasterId,
            broadcaster_user_login: 'edos',
            broadcaster_user_name: 'EDOS',
            chatter_user_id: chatter,
            chatter_user_login: `viewer${chatter}`,
            chatter_user_name: `viewer${chatter}`,
            message_id: id,
            message: { text, fragments: [{ type: 'text', text }] },
            message_type: 'text',
            badges: [],
        },
    });
    const signature = `sha256=${crypto.hmac('sha256', EVENTSUB_SECRET, id + timestamp + body, 'hex')}`;

    const response = http.post(`${BASE_URL}/twitch/eventsub`, body, {
        headers: {
            'Content-Type': 'application/json',
            'Twitch-Eventsub-Message-Id': id,
            'Twitch-Eventsub-Message-Timestamp': timestamp,
            'Twitch-Eventsub-Message-Signature': signature,
            'Twitch-Eventsub-Message-Type': 'notification',
        },
        tags: { name: 'POST /twitch/eventsub (chat)' },
    });
    check(response, { 'chat webhook is accepted (204)': (r) => r.status === 204 });
    reportFailure('chat webhook', response);
}

// --- summary -------------------------------------------------------------------

function ms(value) {
    return value === undefined ? 'n/a' : `${Math.round(value)} ms`;
}

export function handleSummary(data) {
    const rows = SELECTED.map((name) => {
        const duration = data.metrics[`http_req_duration{scenario:${name}}`];
        const failed = data.metrics[`http_req_failed{scenario:${name}}`];
        const values = duration ? duration.values : {};
        const passed = [duration, failed].every((metric) => !metric || !metric.thresholds
            || Object.values(metric.thresholds).every((threshold) => threshold.ok));

        return `| ${name} | ${values.count || 0} | ${ms(values['p(50)'])} | ${ms(values['p(95)'])} | ${ms(values['p(99)'])} | ${ms(values.max)} | ${failed ? (failed.values.rate * 100).toFixed(2) : '0.00'}% | p95 < ${LIMITS[name].p95} ms, p99 < ${LIMITS[name].p99} ms, errors < 1% | ${passed ? 'pass' : '**FAIL**'} |`;
    });
    const checks = data.metrics.checks ? (data.metrics.checks.values.rate * 100).toFixed(2) : 'n/a';

    const markdown = [
        `## ARE load test: ${PROFILE} against ${BASE_URL}`,
        '',
        `Duration ${DURATION}; anonymous ${ANON_RATE}/s` + (PROFILE === 'ci' ? `, ${OVERLAY_SOURCES} overlay sources, ${VIEWERS} viewers, chat burst up to ${CHAT_RATE}/s.` : '.'),
        '',
        '| Scenario | Requests | p50 | p95 | p99 | max | Errors | Thresholds | Result |',
        '|---|---|---|---|---|---|---|---|---|',
        ...rows,
        '',
        `Checks passed: ${checks}%.`,
        '',
        '### By request',
        '',
        '| Request | Count | p50 | p95 | p99 | max |',
        '|---|---|---|---|---|---|',
        ...BREAKDOWN_NAMES.map((name) => {
            const metric = data.metrics[`http_req_duration{name:${name}}`];
            const values = metric ? metric.values : {};

            return `| ${name} | ${values.count || 0} | ${ms(values['p(50)'])} | ${ms(values['p(95)'])} | ${ms(values['p(99)'])} | ${ms(values.max)} |`;
        }),
        '',
    ].join('\n');

    return {
        stdout: `\n${markdown}\n`,
        [__ENV.SUMMARY_PATH || 'loadtest-summary.md']: markdown,
        [__ENV.SUMMARY_JSON || 'loadtest-summary.json']: JSON.stringify(data, null, 2),
    };
}
