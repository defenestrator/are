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

// Both of a question's buttons (Top Suggestions and New Ideas) in one state.
const pressed = async (buttons, value) => {
    await expect(buttons).toHaveCount(2);
    for (const i of [0, 1]) {
        await expect(buttons.nth(i)).toHaveAttribute('aria-pressed', value);
    }
};

// #180: cards are Blade components. The page's own renderless upvote and
// downvote answer with vote-recorded, which live-queue.js writes into both
// lists and the pressed buttons; delete goes through the page as well.
test('a viewer\'s own vote shows in both lists without a reload, and an author deletes their own question', async ({ page }) => {
    await signIn(page, fixtures.viewer, '/vote');

    // The whole page is two Livewire components however many cards it shows.
    await expect(page.locator('[x-data="liveQueue"] li[data-question-id]').first()).toBeVisible();
    expect(await page.locator('[wire\\:id]').count()).toBe(2);

    const question = `E2E own question ${Date.now()}`;
    await page.getByPlaceholder('What should I sing about?').fill(question);
    await page.getByRole('button', { name: 'Submit' }).click();
    const card = page.locator('li[data-question-id]', { hasText: question });
    await expect(card).toHaveCount(2);   // Top Suggestions and New Ideas
    const id = await card.first().getAttribute('data-question-id');

    const counts = page.locator(`[data-vote-count="${id}"]`);
    const up = page.getByRole('button', { name: `Upvote #${id}` });
    const down = page.getByRole('button', { name: `Downvote #${id}` });

    await up.first().click();
    await expect(counts).toHaveText(['1', '1']);
    await pressed(up, 'true');
    await pressed(down, 'false');

    await down.last().click();
    await expect(counts).toHaveText(['-1', '-1']);
    await pressed(up, 'false');
    await pressed(down, 'true');

    // Delete asks first (wire:confirm), then the question leaves both lists.
    page.once('dialog', (dialog) => dialog.accept());
    await card.first().getByRole('button', { name: 'Delete question' }).click();
    await expect(card).toHaveCount(0);
});
