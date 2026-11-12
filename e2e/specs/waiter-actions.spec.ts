import { expect, test } from '@playwright/test';
import { authState } from '../helpers/auth';
import { apiResult, createAreaAndTable, deleteAreaAndTable, listProducts, productStock } from '../helpers/api';
import { confirmCancelMeal, expectTableStatus, readFloorTable, saveMealNote, tableCard } from '../helpers/floor';
import { addProduct, openOrderFromTab, openPos, payInFull, payPartial, saveUnpaidDineIn } from '../helpers/pos';
import { baseURL } from '../load-env';

test('waiter notes and cancels meals, and paid or partial cancels stay blocked', async ({ browser }) => {
    test.setTimeout(300000);
    const admin = await browser.newContext({ storageState: authState('admin'), baseURL: baseURL() });
    const waiter = await browser.newContext({ storageState: authState('waiter'), baseURL: baseURL() });
    const adminPage = await admin.newPage();
    const page = await waiter.newPage();
    const unpaidTable = await createAreaAndTable(adminPage, 'E2E wait');
    const partialTable = await createAreaAndTable(adminPage, 'E2E part');
    const paidTable = await createAreaAndTable(adminPage, 'E2E paid');
    const products = await listProducts(adminPage);
    const note = `No onions ${Date.now().toString(36)}`;
    let unpaidOrder: number | null = null;
    let partialOrder: number | null = null;
    let paidOrder: number | null = null;

    try {
        await openPos(page);
        await addProduct(page, products[0].name);
        const stockBefore = await productStock(adminPage, products[0].id);
        await saveUnpaidDineIn(page, unpaidTable.tableName);

        await page.goto('/dashboard/zash-dine/floor');
        await expectTableStatus(page, unpaidTable.tableName, 'Occupied');
        const before = await readFloorTable(page, unpaidTable.tableName);
        unpaidOrder = before?.current_order_id ?? null;
        const totalBefore = Number(before?.order?.total);
        const card = tableCard(page, unpaidTable.tableName);
        await card.getByRole('button', { name: 'Add note' }).click();
        await saveMealNote(page, note);
        await expect.poll(async () => card.getByText(note).count(), { timeout: 12000 }).toBeGreaterThan(0);

        await card.getByRole('button', { name: 'Cancel meal' }).click();
        await confirmCancelMeal(page);
        await expect(page.locator('#snack-wrapper')).toContainText(/Meal cancelled/i);
        await expect.poll(async () => Number((await readFloorTable(page, unpaidTable.tableName))?.order?.total), {
            timeout: 12000,
        }).toBeLessThan(totalBefore);
        expect(await productStock(adminPage, products[0].id)).toBe(stockBefore);

        await openPos(page);
        await addProduct(page, products[1].name);
        await payPartial(page, partialTable.tableName);
        await page.goto('/dashboard/zash-dine/floor');
        await expectTableStatus(page, partialTable.tableName, 'Occupied');
        partialOrder = (await readFloorTable(page, partialTable.tableName))?.current_order_id ?? null;
        await tableCard(page, partialTable.tableName).getByRole('button', { name: 'Cancel meal' }).click();
        const cancelPopup = page.locator('#zash-dine-cancel-meal-popup');
        await expect(cancelPopup).toBeVisible({ timeout: 15000 });
        await cancelPopup.getByRole('button', { name: 'Cancel meal', exact: true }).click();
        await expect(cancelPopup.getByRole('alert')).toContainText(/Use a refund instead/i);
        await cancelPopup.locator('button', { hasText: /^Keep meal$/ }).click();
        await expect(cancelPopup).toBeHidden({ timeout: 15000 });
        expect((await readFloorTable(page, partialTable.tableName))?.items[0]?.status).not.toBe('cancelled');

        await openPos(page);
        await addProduct(page, products[2].name);
        await saveUnpaidDineIn(page, paidTable.tableName);
        const paying = await readFloorTable(page, paidTable.tableName);
        paidOrder = paying?.current_order_id ?? null;
        const paidItemId = paying?.items[0]?.id ?? 0;
        expect(paidItemId).toBeGreaterThan(0);

        await openPos(page);
        await openOrderFromTab(page, 'Unpaid', paying?.order?.code ?? '');
        await payInFull(page);
        await page.goto('/dashboard/zash-dine/floor');
        await expectTableStatus(page, paidTable.tableName, 'Available');
        await expect(tableCard(page, paidTable.tableName).getByRole('button', { name: 'Add note' })).toHaveCount(0);
        await expect(tableCard(page, paidTable.tableName).getByRole('button', { name: 'Cancel meal' })).toHaveCount(0);

        const cancel = await apiResult(page, 'post', `/api/zash-dine/order-items/${paidItemId}/cancel`);
        const noted = await apiResult(page, 'put', `/api/zash-dine/order-items/${paidItemId}/note`, { note: 'too late' });
        expect(cancel.status).toBe(403);
        expect(String(cancel.body.message)).toMatch(/no longer active on the floor/i);
        expect(noted.status).toBe(403);
        expect(String(noted.body.message)).toMatch(/no longer active on the floor/i);
    } finally {
        await deleteAreaAndTable(adminPage, unpaidTable.areaId, unpaidTable.tableId, unpaidOrder);
        await deleteAreaAndTable(adminPage, partialTable.areaId, partialTable.tableId, partialOrder);
        await deleteAreaAndTable(adminPage, paidTable.areaId, paidTable.tableId, paidOrder);
        await waiter.close();
        await admin.close();
    }
});
