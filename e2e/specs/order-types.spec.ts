import { expect, test } from '@playwright/test';
import { authState } from '../helpers/auth';
import { openPos } from '../helpers/pos';

test.use({ storageState: authState('admin') });

test('POS offers Take Away, Delivery and Dine-in', async ({ page }) => {
    await openPos(page);
    await page.getByRole('button', { name: 'Order Type' }).click();
    await expect(page.getByText('Define The Order Type')).toBeVisible();
    await expect(page.getByText('Take Away', { exact: true })).toBeVisible();
    await expect(page.getByText('Delivery', { exact: true })).toBeVisible();
    await expect(page.getByText('Dine-in', { exact: true })).toBeVisible();
});
