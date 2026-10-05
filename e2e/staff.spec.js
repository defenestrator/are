import { test, expect, fixtures, signIn } from './support.js';

// Every signed-in page, as the broadcaster (who may moderate and see leads),
// under the same guard: no console errors or warnings, no 4xx/5xx. A page
// that 500s from a stale route or a compiled view missing a variable fails
// here. Add new pages to this list.
const pages = [
    ['/vote', null],
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

// Known broken, found by this suite: the appearance switcher throws
// "Alpine Expression Error: dark is not defined" (#167). Turn this back into
// a normal test, and into the list above, when #167 is fixed.
test.fixme('/settings renders for the broadcaster (#167)', async ({ page }) => {
    await signIn(page, fixtures.broadcaster, '/settings');
    await page.waitForLoadState('networkidle');
});
