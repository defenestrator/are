import { test, expect, fixtures, signIn, signedInPage } from './support.js';

// CI builds without a Reverb key, so the vote page has no socket and keeps
// itself current with its polling fallback (every 5 to 10 seconds).
test('a signed-in viewer submits a question and votes, and sees another viewer\'s vote arrive by polling', async ({ page, browser, baseURL }, testInfo) => {
    await signIn(page, fixtures.viewer, '/vote');
    await expect(page).toHaveURL(/\/vote$/);

    // Submit.
    const question = `E2E question ${Date.now()}`;
    await page.getByPlaceholder('What should I sing about?').fill(question);
    await page.getByRole('button', { name: 'Submit' }).click();
    await expect(page.getByText(question).first()).toBeVisible();

    // Vote on the seeded question; the count updates on this page at once.
    const count = page.locator(`[data-vote-count="${fixtures.question}"]`).first();
    await expect(count).toHaveText('0');
    await page.getByRole('button', { name: `Upvote #${fixtures.question}` }).first().click();
    await expect(count).toHaveText('1');

    // Another viewer votes in their own browser. This page only learns of it
    // through the polling fallback.
    const other = await signedInPage(browser, baseURL, testInfo.project.use.userAgent, fixtures.otherViewer, '/vote');
    try {
        await other.page.getByRole('button', { name: `Upvote #${fixtures.question}` }).first().click();
        await expect(other.page.locator(`[data-vote-count="${fixtures.question}"]`).first()).toHaveText('2');
        expect(other.problems, 'console, HTTP or token problems for the other viewer').toEqual([]);
    } finally {
        await other.context.close();
    }

    await expect(count).toHaveText('2', { timeout: 20_000 });
});
