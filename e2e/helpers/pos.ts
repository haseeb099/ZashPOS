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

    await expect.poll(async () => page.evaluate(() => {
        const queue = (window as unknown as { POS?: { orderTypeQueue?: Array<{ identifier?: string }> } }).POS?.orderTypeQueue;

        return Array.isArray(queue) && queue.some((entry) => entry?.identifier === 'zash-dine/dine-in');
    }), { timeout: 15000 }).toBe(true);
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

async function chooseDineIn(page: Page, tableName: string, fallbackId?: number): Promise<void> {
    await expect(page.getByText('Define The Order Type')).toBeVisible();
    const prompt = Promise.race([
        pickTable(page, tableName, fallbackId),
        new Promise<string>((_, reject) => {
            setTimeout(() => reject(new Error(`Timed out waiting for the dine-in table prompt for "${tableName}".`)), 20000);
        }),
    ]);
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
    await ensureOrderReady(page);
}

export async function openHeldOrder(page: Page, reference: string): Promise<void> {
    await page.getByRole('button', { name: 'Orders' }).click();
    const popup = page.locator('.ns-box').filter({ hasText: 'On Hold' });
    const row = popup.locator('[data-order-id]').filter({ hasText: reference });
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Open' }).click();
    await expect(page.locator('#cart-products-table')).not.toContainText('No products added');
    await ensureOrderReady(page);
}

export type PosCustomerSnapshot = {
    id: number | null;
    first_name: string | null;
    last_name: string | null;
    group_id: number | null;
    minimal_credit_payment: number | null;
};

export async function readPosCustomer(page: Page): Promise<PosCustomerSnapshot> {
    return page.evaluate(() => {
        const order = (window as unknown as {
            POS?: {
                order?: {
                    getValue?: () => {
                        customer?: {
                            id?: number;
                            first_name?: string;
                            last_name?: string;
                            group_id?: number;
                            group?: { id?: number; minimal_credit_payment?: number };
                        };
                    };
                };
            };
        }).POS?.order?.getValue?.();
        const customer = order?.customer;
        const group = customer?.group;

        return {
            id: customer?.id ?? null,
            first_name: customer?.first_name ?? null,
            last_name: customer?.last_name ?? null,
            group_id: group?.id ?? customer?.group_id ?? null,
            minimal_credit_payment: group?.minimal_credit_payment ?? null,
        };
    });
}

async function ensureOrderReady(page: Page): Promise<void> {
    await expect.poll(async () => page.evaluate(() => {
        const order = (window as unknown as {
            POS?: {
                order?: {
                    getValue?: () => {
                        products?: unknown[];
                        customer?: { id?: number; group?: { minimal_credit_payment?: number | null } };
                        type?: unknown;
                    };
                };
            };
        }).POS?.order?.getValue?.();
        const group = order?.customer?.group;

        return Boolean(order?.customer?.id)
            && Boolean(order?.type)
            && Array.isArray(order?.products)
            && order.products.length > 0
            && Boolean(group)
            && group.minimal_credit_payment !== undefined
            && group.minimal_credit_payment !== null;
    })).toBe(true);
}

export async function payInFull(page: Page, tableName?: string): Promise<void> {
    await ensureOrderReady(page);

    const customerBefore = await readPosCustomer(page);
    expect(customerBefore.id).not.toBeNull();
    expect(customerBefore.minimal_credit_payment).not.toBeNull();

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
        const customerDuringPayment = await readPosCustomer(page);
        expect(customerDuringPayment.id).toBe(customerBefore.id);
        expect(customerDuringPayment.minimal_credit_payment).toBe(customerBefore.minimal_credit_payment);
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
