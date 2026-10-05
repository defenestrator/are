import { test, expect } from './support.js';

test('the home page renders', async ({ page }) => {
    const response = await page.goto('/');

    expect(response.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Applied Research Equity' })).toBeVisible();
});

test('the about page renders with its lead form', async ({ page }) => {
    await page.goto('/about');

    await expect(page.getByRole('heading', { level: 1, name: 'Who we are' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send enquiry' })).toBeVisible();
});

test('the stream-safe music pack lists the seeded track', async ({ page }) => {
    await page.goto('/music');

    await expect(page.getByRole('heading', { level: 1, name: 'Stream-safe music pack' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'E2E Stream Safe Song' })).toBeVisible();
});
