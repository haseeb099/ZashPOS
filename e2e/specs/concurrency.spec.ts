import { expect, test } from '@playwright/test';
import { authState } from '../helpers/auth';
import { createAreaAndTable, deleteAreaAndTable, listProducts, orderCount } from '../helpers/api';
import { readFloorTable } from '../helpers/floor';
import { addProduct, openPos } from '../helpers/pos';
import { pickTable } from '../helpers/pick-table';
import { baseURL } from '../load-env';

test('exactly one of two simultaneous dine-in orders takes a free table', async ({ browser }) => {
    const admin = await browser.newContext({ storageState: authState('admin'), baseURL: baseURL() });
    const waiter = await browser.newContext({ storageState: authState('waiter'), baseURL: baseURL() });
    const pageA = await admin.newPage();
    const pageB = await waiter.newPage();
    const created = await createAreaAndTable(pageA, 'E2E race');
    const products = await listProducts(pageA);
    const before = await orderCount(pageA);

    try {
        await openPos(pageA);
        await openPos(pageB);
        await addProduct(pageA, products[0].name);
        await addProduct(pageB, products[1].name);

        const ready = async (page: typeof pageA, reference: string) => {
            const prompt = pickTable(page, created.tableName);
            await page.locator('#hold-button').click();
            await page.getByText('Dine-in', { exact: true }).click();
            await prompt;
            const popup = page.locator('.ns-box').filter({ hasText: 'Hold Order' });
            await popup.getByPlaceholder('Order Reference').fill(reference);

            return popup;
        };

        const [popupA, popupB] = await Promise.all([
            ready(pageA, `race-a-${Date.now().toString(36)}`),
            ready(pageB, `race-b-${Date.now().toString(36)}`),
        ]);

        await Promise.all([
            popupA.getByText('Confirm', { exact: true }).click(),
            popupB.getByText('Confirm', { exact: true }).click(),
        ]);

        const outcome = async (page: typeof pageA): Promise<'placed' | 'blocked'> => Promise.race([
            page.getByText('The order has been placed.').waitFor({ state: 'visible', timeout: 30000 }).then(() => 'placed' as const),
            page.getByText(/already occupied/i).waitFor({ state: 'visible', timeout: 30000 }).then(() => 'blocked' as const),
        ]);

        const results = await Promise.all([outcome(pageA), outcome(pageB)]);
        expect(results.filter((result) => result === 'placed')).toHaveLength(1);
        expect(results.filter((result) => result === 'blocked')).toHaveLength(1);
        expect(await orderCount(pageA)).toBe(before + 1);
    } finally {
        const table = await readFloorTable(pageA, created.tableName);
        await deleteAreaAndTable(pageA, created.areaId, created.tableId, table?.current_order_id);
        await admin.close();
        await waiter.close();
    }
});
