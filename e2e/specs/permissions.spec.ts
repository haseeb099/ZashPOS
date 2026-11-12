import { expect, test } from '@playwright/test';
import { authState } from '../helpers/auth';
import { baseURL } from '../load-env';

test('waiter sees Floor and is denied Areas and Tables', async ({ browser }) => {
    const context = await browser.newContext({ storageState: authState('waiter'), baseURL: baseURL() });
    const page = await context.newPage();

    try {
        await page.goto('/dashboard/zash-dine/floor');
        await expect(page.getByRole('heading', { name: 'Floor' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Refresh now' })).toBeVisible();

        await page.goto('/dashboard/zash-dine/areas');
        await expect(page.getByRole('heading', { name: 'Not Enough Permissions' })).toBeVisible();

        await page.goto('/dashboard/zash-dine/tables');
        await expect(page.getByRole('heading', { name: 'Not Enough Permissions' })).toBeVisible();
    } finally {
        await context.close();
    }
});

test('cashier is denied Floor', async ({ browser }) => {
    const context = await browser.newContext({ storageState: authState('cashier'), baseURL: baseURL() });
    const page = await context.newPage();

    try {
        await page.goto('/dashboard/zash-dine/floor');
        await expect(page.getByRole('heading', { name: 'Not Enough Permissions' })).toBeVisible();
    } finally {
        await context.close();
    }
});
