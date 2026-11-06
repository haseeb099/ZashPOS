import { defineConfig, devices } from '@playwright/test';
import { baseURL } from './load-env';

export default defineConfig({
    testDir: './specs',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 180000,
    expect: {
        timeout: 15000,
    },
    reporter: [
        ['list'],
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ],
    outputDir: 'test-results',
    globalSetup: './global-setup.ts',
    use: {
        baseURL: baseURL(),
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        viewport: { width: 1280, height: 800 },
        ignoreHTTPSErrors: true,
        actionTimeout: 15000,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
