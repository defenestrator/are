import { test as plain } from '@playwright/test';
import { test, expect, fixtures } from './support.js';

// Every OBS overlay, in both canvases, through the real token flow: the
// #token= fragment is traded for a grant cookie and the page reloads. The
// shared guard fails on any console error or warning, any 4xx/5xx, and any
// request URL that carries the token.
const layouts = { horizontal: { width: 1920, height: 1080 }, vertical: { width: 1080, height: 1920 } };

// Extra query parameters an operator gives an overlay in OBS. The visualizer
// listens to show audio; without ?audio= it warns that it has none.
const extraQuery = { visualizer: '&audio=default' };

for (const [overlay, token] of Object.entries(fixtures.overlayTokens)) {
    for (const [layout, size] of Object.entries(layouts)) {
        test(`overlay ${overlay} (${layout}) loads with its fragment token on a transparent page`, async ({ page }) => {
            await page.setViewportSize(size);

            const exchanged = page.waitForResponse((response) => response.url().endsWith(`/overlay/${overlay}/session`));
            await page.goto(`/overlay/${overlay}?layout=${layout}${extraQuery[overlay] ?? ''}#token=${token}`);
            expect((await exchanged).status()).toBe(204);

            // After the reload the real overlay is served, not the bootstrap page.
            await expect(page.locator(`html[data-overlay="${overlay}"][data-layout="${layout}"]`)).toHaveCount(1);
            await expect(page.getByText('Connecting the overlay.')).toHaveCount(0);
            await page.waitForLoadState('networkidle');

            const backgrounds = await page.evaluate(() => [document.documentElement, document.body]
                .map((element) => getComputedStyle(element).backgroundColor));
            expect(backgrounds).toEqual(['rgba(0, 0, 0, 0)', 'rgba(0, 0, 0, 0)']);

            // The token stays in the fragment, never in the address the server saw.
            expect(new URL(page.url()).search).not.toContain(token);
        });
    }
}

// Unguarded on purpose: the console warning is what this checks for.
plain('an overlay without a token shows only the bootstrap page and says what to do', async ({ page }) => {
    const warnings = [];
    page.on('console', (message) => warnings.push(message.text()));

    const response = await page.goto('/overlay/queue?layout=horizontal');

    expect(response.status()).toBe(200);
    await expect(page.getByText('Connecting the overlay.')).toBeVisible();
    await expect.poll(() => warnings.some((text) => text.includes('No #token= in the URL'))).toBe(true);
});
