import { expect, type Page } from '@playwright/test';
import { expectSnack } from './api';
import { pickTable } from './pick-table';

export async function openPos(page: Page): Promise<void> {
    await page.goto('/dashboard/pos');
    await expect(page.locator('#pay-button')).toBeVisible({ timeout: 30000 });

    await expect.poll(async () => {
        const selecting = page.getByText('Select Customer', { exact: true });

        if (await selecting.isVisible().catch(() => false)) {
            const row = page.locator('#ns-pos-customer-select-popup li.cursor-pointer').first();

            if (await row.count()) {
                await row.click();
            }
        }

        return page.evaluate(() => Boolean((window as unknown as { POS?: { order?: { getValue?: () => { customer?: unknown } } } }).POS?.order?.getValue?.()?.customer));
    }, { timeout: 30000 }).toBe(true);
}

export async function addProduct(page: Page, productName: string): Promise<void> {
    const grid = page.locator('#grid-items');
    const product = grid.getByRole('heading', { name: productName, exact: true });

    if (await product.count() === 0) {
        const categoryNames = await grid.getByRole('heading').allTextContents();

        for (const categoryName of categoryNames) {
            await grid.getByRole('heading', { name: categoryName, exact: true }).click();
            const found = await product.waitFor({ state: 'visible', timeout: 8000 }).then(() => true).catch(() => false);

            if (found) {
                break;
            }

            await page.locator('#grid-breadcrumb').getByRole('link', { name: 'Home', exact: true }).click();
            await expect(grid.getByRole('heading').first()).toBeVisible();
        }
    }

    await product.click();

    const quantityPopup = page.locator('.ns-box').filter({ has: page.getByRole('heading', { name: 'Define Quantity' }) });
    const unitKey = page.locator('#ns-units-selector .ns-numpad-key').first();
    const quantityOpened = await quantityPopup.waitFor({ state: 'visible', timeout: 8000 }).then(() => true).catch(() => false);

    if (!quantityOpened) {
        await unitKey.click();
        await expect(quantityPopup).toBeVisible();
    }

    await quantityPopup.getByText('Enter', { exact: true }).click();
    await expect(page.locator('#cart-products-table')).toContainText(productName);
}

async function chooseDineIn(page: Page, tableName: string): Promise<void> {
    await expect(page.getByText('Define The Order Type')).toBeVisible();
    const prompt = pickTable(page, tableName);
    await page.getByText('Dine-in', { exact: true }).click();
    await prompt;
}

export async function saveUnpaidDineIn(page: Page, tableName: string): Promise<void> {
    await page.locator('#pay-button').click();
    await chooseDineIn(page, tableName);
    await expect(page.locator('#ns-payment-popup')).toBeVisible();
    await page.getByRole('button', { name: /Save As Unpaid/ }).click();
    await page.locator('#confirm-popup').getByRole('button', { name: 'Yes' }).click();
    await expect(page.locator('#ns-payment-popup')).toBeHidden({ timeout: 30000 });
}

export async function holdDineIn(page: Page, tableName: string, reference: string): Promise<void> {
    await page.locator('#hold-button').click();
    await chooseDineIn(page, tableName);
    const popup = page.locator('.ns-box').filter({ hasText: 'Hold Order' });
    await popup.getByPlaceholder('Order Reference').fill(reference);
    await popup.getByText('Confirm', { exact: true }).click();
    await expectSnack(page, 'The order has been placed.');
}

export async function holdOpenOrder(page: Page, reference: string): Promise<void> {
    await page.locator('#hold-button').click();
    const popup = page.locator('.ns-box').filter({ hasText: 'Hold Order' });
    await popup.getByPlaceholder('Order Reference').fill(reference);
    await popup.getByText('Confirm', { exact: true }).click();
    await expectSnack(page, /The order has been updated|The order has been placed/);
}

export async function openOrderFromTab(page: Page, tab: string, code: string): Promise<void> {
    await page.getByRole('button', { name: 'Orders' }).click();
    const popup = page.locator('.ns-box').filter({ hasText: 'Orders' });
    await popup.getByText(tab, { exact: true }).click();
    const row = popup.locator('[data-order-id]').filter({ hasText: code });
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Open' }).click();
    const confirm = page.locator('#confirm-popup');

    if (await confirm.isVisible().catch(() => false)) {
        await confirm.getByRole('button', { name: 'Yes' }).click();
    }

    await expect(page.locator('#cart-products-table')).not.toContainText('No products added');
}

export async function openHeldOrder(page: Page, reference: string): Promise<void> {
    await page.getByRole('button', { name: 'Orders' }).click();
    const popup = page.locator('.ns-box').filter({ hasText: 'On Hold' });
    const row = popup.locator('[data-order-id]').filter({ hasText: reference });
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Open' }).click();
    await expect(page.locator('#cart-products-table')).not.toContainText('No products added');
}

async function ensureOrderReady(page: Page): Promise<void> {
    await expect.poll(async () => page.evaluate(() => {
        const order = (window as unknown as { POS?: { order?: { getValue?: () => { products?: unknown[]; customer?: unknown; type?: unknown } } } }).POS?.order?.getValue?.();

        return Boolean(order?.customer) && Boolean(order?.type) && Array.isArray(order?.products) && order.products.length > 0;
    })).toBe(true);
}

export async function payInFull(page: Page, tableName?: string): Promise<void> {
    await ensureOrderReady(page);

    if (!tableName) {
        await page.getByText(/Customer:/).click();
        await page.locator('#ns-pos-customer-select-popup li.cursor-pointer').first().click();
        await ensureOrderReady(page);
    }

    const logs: string[] = [];
    const onConsole = (message: { type: () => string; text: () => string }): void => {
        if (message.type() === 'error' || message.type() === 'warning') {
            logs.push(`${message.type()}: ${message.text()}`);
        }
    };
    const onPageError = (error: Error): void => {
        logs.push(`pageerror: ${error.stack ?? error.message}`);
    };
    page.on('console', onConsole);
    page.on('pageerror', onPageError);
    await page.locator('#pay-button').click();

    if (tableName) {
        await chooseDineIn(page, tableName);
    }

    try {
        await expect(page.locator('#ns-payment-popup')).toBeVisible();
    } catch (error) {
        throw new Error(`${String(error)}\nconsole:\n${logs.slice(-20).join('\n')}`, { cause: error });
    } finally {
        page.off('console', onConsole);
        page.off('pageerror', onPageError);
    }

    await page.getByText('Full Payment', { exact: true }).click();
    await page.locator('#confirm-popup').getByRole('button', { name: 'Yes' }).click();
    await expect(page.locator('#ns-payment-popup')).toBeHidden({ timeout: 30000 });
}

export async function payPartial(page: Page, tableName: string): Promise<void> {
    await page.locator('#pay-button').click();
    await chooseDineIn(page, tableName);
    await expect(page.locator('#ns-payment-popup')).toBeVisible();
    await page.locator('#numpad').getByText('1', { exact: true }).click();
    await page.locator('#numpad .la-share').click();
    await page.getByRole('button', { name: 'Update' }).click();
    await expect(page.locator('#ns-payment-popup')).toBeHidden({ timeout: 30000 });
}

export async function deleteOrderFromList(page: Page, code: string): Promise<void> {
    await page.goto('/dashboard/orders');
    const search = page.locator('#crud-search-box input');
    await search.fill(code);
    await search.press('Enter');
    const row = page.locator('tr').filter({ hasText: code });
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Options' }).click();
    await page.getByRole('menuitem', { name: /Delete/ }).click();
    await page.locator('#confirm-popup').getByRole('button', { name: 'Yes' }).click();
    await expectSnack(page, /deleted/i);
}
