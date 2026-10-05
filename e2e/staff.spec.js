import { test, expect, fixtures, signIn } from './support.js';

// Every signed-in page, as the broadcaster (who may moderate and see leads),
// under the same guard: no console errors or warnings, no 4xx/5xx. A page
// that 500s from a stale route or a compiled view missing a variable fails
// here. Add new pages to this list.
const pages = [
    ['/vote', null],
    ['/settings', null],
    ['/moderation', 'Moderation'],
    ['/clips', 'Clips'],
    ['/bus', 'Chat Control Bus'],
    ['/agent', 'VTuber agent'],
    ['/leads', 'Leads'],
    ['/admin/attribution', null],
    ['/admin/readiness', 'Launch readiness'],
    ['/music/catalogue', null],
    ['/music/requests', null],
];

for (const [path, heading] of pages) {
    test(`${path} renders for the broadcaster`, async ({ page }) => {
        await signIn(page, fixtures.broadcaster, path);

        await expect(page).toHaveURL(new RegExp(`${path.replace(/\//g, '\\/')}$`));
        if (heading !== null) {
            await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
        }
        await page.waitForLoadState('networkidle');
    });
}

// #167: the appearance switcher threw "dark is not defined" on every load.
// It must bind to $flux.appearance, and choosing a value must apply it.
test('the appearance switcher on /settings switches to dark and back', async ({ page }) => {
    await signIn(page, fixtures.broadcaster, '/settings');

    await page.locator('ui-radio', { hasText: 'Dark' }).click();
    await expect(page.locator('html')).toHaveClass(/(^|\s)dark(\s|$)/);
    expect(await page.evaluate(() => window.localStorage.getItem('flux.appearance'))).toBe('dark');

    await page.locator('ui-radio', { hasText: 'Light' }).click();
    await expect(page.locator('html')).not.toHaveClass(/(^|\s)dark(\s|$)/);
    expect(await page.evaluate(() => window.localStorage.getItem('flux.appearance'))).toBe('light');
});
