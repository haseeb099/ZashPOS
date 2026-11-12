import { chromium, type FullConfig } from '@playwright/test';
import fs from 'node:fs';
import { baseURL, e2ePath, loadEnv } from './load-env';
import { login } from './helpers/auth';

async function globalSetup(_config: FullConfig): Promise<void> {
    loadEnv();

    const url = baseURL();
    const roles = [
        ['admin', process.env.E2E_ADMIN_USER, process.env.E2E_ADMIN_PASSWORD],
        ['waiter', process.env.E2E_WAITER_USER, process.env.E2E_WAITER_PASSWORD],
        ['cashier', process.env.E2E_CASHIER_USER, process.env.E2E_CASHIER_PASSWORD],
        ['chef', process.env.E2E_CHEF_USER, process.env.E2E_CHEF_PASSWORD],
    ] as const;

    fs.mkdirSync(e2ePath('auth-state'), { recursive: true });

    const browser = await chromium.launch();

    try {
        for (const [name, username, password] of roles) {
            if (!username || !password) {
                throw new Error(`Set ${name} credentials in e2e/.env before running Playwright.`);
            }

            const context = await browser.newContext({ baseURL: url });
            const page = await context.newPage();
            await login(page, username, password);
            await context.storageState({ path: e2ePath('auth-state', `${name}.json`) });
            await context.close();
        }
    } finally {
        await browser.close();
    }
}

export default globalSetup;
