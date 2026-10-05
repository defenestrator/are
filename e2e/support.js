// Shared by every spec: the seeded fixtures, and a `page` that fails the test
// on anything a viewer or OBS would suffer from but Pest cannot see (#155).
import { test as base, expect } from '@playwright/test';
import fs from 'node:fs';

/** Written by `php artisan db:seed --class=E2eSeeder`. */
export const fixtures = JSON.parse(fs.readFileSync(new URL('./.fixtures.json', import.meta.url), 'utf8'));

const secrets = Object.values(fixtures.overlayTokens);

/**
 * Console messages Chromium itself writes when headless Chromium renders
 * WebGL in software (the visualizer). They come from the browser's GPU
 * process, not from ARE, and a real OBS source on a GPU does not see them.
 * Keep this list to Chromium's own messages only.
 */
const CHROMIUM_GPU_NOISE = [
    /^\[\.WebGL-[^\]]+\]GL Driver Message \(OpenGL, Performance, [^)]*\): GPU stall due to ReadPixels/,
    /Automatic fallback to software WebGL has been deprecated/,
];

/** A URL without its #fragment, which may hold an overlay token. */
const safe = (url) => url.split('#')[0];

// A 1x1 transparent PNG, for stubbed images.
const PIXEL = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');

/**
 * Watch a page. Returns the list of problems, filled as the page runs:
 * - any console error or warning, and any uncaught page error;
 * - any 4xx or 5xx response from this server;
 * - any request URL that contains an overlay token (tokens belong in the
 *   #fragment, which browsers never send).
 * Requests to other hosts (web fonts, avatars) are answered locally with an
 * empty 200, so the run never depends on the internet.
 */
export async function guard(page, origin) {
    const problems = [];

    await page.route((url) => url.origin !== origin, (route) => {
        const type = route.request().resourceType();

        const headers = { 'Access-Control-Allow-Origin': '*' };

        return route.fulfill(type === 'image'
            ? { status: 200, headers, contentType: 'image/png', body: PIXEL }
            : { status: 200, headers, contentType: type === 'stylesheet' ? 'text/css' : 'text/plain', body: '' });
    });

    page.on('console', (message) => {
        if ((message.type() === 'error' || message.type() === 'warning')
            && !CHROMIUM_GPU_NOISE.some((pattern) => pattern.test(message.text()))) {
            problems.push(`console ${message.type()} on ${safe(page.url())}: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => problems.push(`uncaught error on ${safe(page.url())}: ${error.message}`));
    page.on('response', (response) => {
        if (response.status() >= 400 && new URL(response.url()).origin === origin) {
            problems.push(`HTTP ${response.status()} for ${response.request().method()} ${safe(response.url())}`);
        }
    });
    page.on('request', (request) => {
        if (secrets.some((secret) => request.url().includes(secret))) {
            // Never print the token itself.
            problems.push(`an overlay token was sent in a request URL: ${request.method()} ${new URL(request.url()).pathname}`);
        }
    });

    return problems;
}

export const test = base.extend({
    page: async ({ page, baseURL }, use) => {
        const problems = await guard(page, new URL(baseURL).origin);

        await use(page);

        expect(problems, 'console, HTTP or token problems').toEqual([]);
    },
});

/** A fresh browser context signed in as a seeded user, guarded like `page`. */
export async function signedInPage(browser, baseURL, userAgent, userId, to = '/vote') {
    const context = await browser.newContext({ baseURL, userAgent });
    const page = await context.newPage();
    const problems = await guard(page, new URL(baseURL).origin);
    await page.goto(`/_e2e/login/${userId}?to=${encodeURIComponent(to)}`);

    return { context, page, problems };
}

export async function signIn(page, userId, to = '/vote') {
    await page.goto(`/_e2e/login/${userId}?to=${encodeURIComponent(to)}`);
}

export { expect };
