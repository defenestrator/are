// Browser end-to-end smoke tests (#155). Run from the repository root:
//
//   php artisan migrate:fresh --seed --seeder=E2eSeeder   (APP_ENV=testing)
//   npm run build
//   npm run test:e2e
//
// CI does this against the Postgres service; see .github/workflows/ci.yml.
// Every spec fails on any console error or warning, any 4xx/5xx from this
// server, and any request URL carrying an overlay token (e2e/support.js).
import { defineConfig, devices } from '@playwright/test';

const port = process.env.E2E_PORT ?? '8123';
const baseURL = `http://127.0.0.1:${port}`;

// A stock desktop Chrome user agent. Headless Chromium's own says
// "HeadlessChrome", which ShortLink treats as a bot, so /go would not record
// the click or the attribution the lead form reads.
const userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

export default defineConfig({
    testDir: '.',
    timeout: 30_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: !!process.env.CI,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]] : 'list',
    outputDir: 'test-results',
    use: {
        baseURL,
        userAgent,
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                userAgent,
                // A fake microphone, granted without a prompt, so the
                // visualizer overlay can open ?audio=default as OBS would.
                permissions: ['microphone'],
                launchOptions: { args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] },
            },
        },
    ],
    webServer: {
        // Several workers, so Livewire requests and the vote page's polling
        // never queue behind each other on PHP's single-threaded server.
        command: `php artisan serve --host=127.0.0.1 --port=${port} --no-reload`,
        cwd: '..',
        url: `${baseURL}/up`,
        env: { PHP_CLI_SERVER_WORKERS: '4' },
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
