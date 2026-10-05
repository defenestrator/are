// The guard is what makes every other spec worth running, so check that it
// really catches each kind of problem. These use the plain Playwright test,
// because a guarded page would (rightly) fail on purpose here.
import { test, expect } from '@playwright/test';
import { fixtures, guard } from './support.js';

test('the guard reports console errors and warnings, uncaught errors, 4xx/5xx and tokens in URLs', async ({ page, baseURL }) => {
    const origin = new URL(baseURL).origin;
    const token = fixtures.overlayTokens.queue;
    const problems = await guard(page, origin);

    await page.goto('/about');
    await page.evaluate(async (secret) => {
        console.error('an error');
        console.warn('a warning');
        setTimeout(() => { throw new Error('uncaught'); }, 0);
        await fetch('/this-page-does-not-exist').catch(() => {});
        await fetch(`/about?leak=${secret}`).catch(() => {});
    }, token);

    await expect.poll(() => problems.length).toBeGreaterThanOrEqual(5);
    const report = problems.join('\n');

    expect(report).toContain('console error');
    expect(report).toContain('console warning');
    expect(report).toContain('uncaught error');
    expect(report).toContain('HTTP 404 for GET');
    expect(report).toContain('an overlay token was sent in a request URL');
    // The report itself never carries the token.
    expect(report).not.toContain(token);
});

test('the guard answers requests to other hosts locally, so no run depends on the internet', async ({ page, baseURL }) => {
    const problems = await guard(page, new URL(baseURL).origin);
    await page.goto('/about');

    const status = await page.evaluate(() => fetch('https://example.invalid/anything').then((response) => response.status));

    expect(status).toBe(200);
    expect(problems).toEqual([]);
});
