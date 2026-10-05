import { test, expect, fixtures, signIn } from './support.js';

test('a /go link lands on /about, and the lead sent from there is stored with its attribution', async ({ page }) => {
    // /go/{code} 302s to its destination with the link's UTM tags.
    await page.goto(`/go/${fixtures.shortLinkCode}`);
    await expect(page).toHaveURL(/\/about\?.*utm_campaign=e2e-smoke/);

    const email = `e2e-${Date.now()}@example.test`;
    await page.getByLabel('Name', { exact: true }).fill('E2E Lead');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('What are you working on?').fill('Checking the lead form end to end.');

    // Without consent the form refuses and says why.
    await page.getByRole('button', { name: 'Send enquiry' }).click();
    await expect(page.getByText('Please tick the box so we can store your enquiry and reply to it.')).toBeVisible();

    await page.getByLabel('EDOS may store these details and contact me by email about this enquiry.').check();
    await page.getByRole('button', { name: 'Send enquiry' }).click();
    await expect(page.getByText('Thanks, we got it.')).toBeVisible();

    // Stored, with the campaign from the short link: the broadcaster sees it on /leads.
    await signIn(page, fixtures.broadcaster, '/leads');
    const row = page.getByRole('row').filter({ hasText: email });
    await expect(row).toBeVisible();
    await expect(row).toContainText('e2e-smoke');
});
