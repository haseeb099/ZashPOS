import { expect, test } from '@playwright/test';
import { authState } from '../helpers/auth';
import {
    createAreaAndTable,
    deleteAreaAndTable,
    listProducts,
    orderCount,
    productStock,
    setCustomerGroupMinimalCreditPayment,
} from '../helpers/api';
import { expectTableStatus, readFloorTable, tableCard } from '../helpers/floor';
import { pickTable } from '../helpers/pick-table';
import {
    addProduct,
    deleteOrderFromList,
    openOrderFromTab,
    openPos,
    payInFull,
    payPartial,
    readPosCustomer,
    saveUnpaidDineIn,
} from '../helpers/pos';

test.use({ storageState: authState('admin') });

test('dine-in occupies a table, rejects a second order, then frees on pay or delete', async ({ page }) => {
    test.setTimeout(360000);
    const created = await createAreaAndTable(page, 'E2E dine');
    const products = await listProducts(page);
    const [first, second, third] = products;
    let orderId: number | null = null;
    let restoredGroupId: number | null = null;
    let restoredMinimalPayment: number | null = null;

    try {
        const floor = await page.context().newPage();
        await floor.goto('/dashboard/zash-dine/floor');
        await expect(floor.getByText(created.areaName)).toBeVisible();
        await expect(tableCard(floor, created.tableName).getByText('Available', { exact: true })).toBeVisible();

        const stockBefore = await productStock(page, first.id);
        const ordersBefore = await orderCount(page);

        await openPos(page);
        const customerAtSale = await readPosCustomer(page);
        expect(customerAtSale.id).not.toBeNull();
        expect(customerAtSale.group_id).not.toBeNull();
        restoredGroupId = customerAtSale.group_id;
        restoredMinimalPayment = customerAtSale.minimal_credit_payment;
        await setCustomerGroupMinimalCreditPayment(page, customerAtSale.group_id as number, 40);
        await addProduct(page, first.name);
        await saveUnpaidDineIn(page, created.tableName);

        const stockAfterSale = await productStock(page, first.id);
        expect(stockAfterSale).toBeLessThan(stockBefore);
        const ordersAfterSale = await orderCount(page);
        expect(ordersAfterSale).toBe(ordersBefore + 1);

        await expectTableStatus(floor, created.tableName, 'Occupied');
        await expect(tableCard(floor, created.tableName)).toContainText(first.name);
        await floor.close();

        /**
         * The dine-in prompt only opens when at least one free table exists.
         * Keep a spare free table so the occupied-table rejection can still
         * type the occupied id through the prompt fallback.
         */
        const spare = await createAreaAndTable(page, 'E2E spare');

        try {
            await openPos(page);
            await addProduct(page, second.name);
            const stockBeforeReject = await productStock(page, second.id);
            const ordersBeforeReject = await orderCount(page);
            await page.locator('#pay-button').click();
            await expect(page.getByText('Define The Order Type')).toBeVisible();
            const prompt = Promise.race([
                pickTable(page, created.tableName, created.tableId),
                new Promise<string>((_, reject) => {
                    setTimeout(() => reject(new Error('Timed out waiting for occupied-table dine-in prompt.')), 20000);
                }),
            ]);
            await page.getByText('Dine-in', { exact: true }).click();
            await prompt;
            await page.getByRole('button', { name: /Save As Unpaid/ }).click();
            await page.locator('#confirm-popup').getByRole('button', { name: 'Yes' }).click();
            await expect(page.locator('#snack-wrapper')).toContainText(/already occupied/i);

            expect(await productStock(page, second.id)).toBe(stockBeforeReject);
            expect(await orderCount(page)).toBe(ordersBeforeReject);
        } finally {
            await deleteAreaAndTable(page, spare.areaId, spare.tableId);
        }

        const occupied = await readFloorTable(page, created.tableName);
        orderId = occupied?.current_order_id ?? null;
        expect(occupied?.order?.payment_status).toBe('unpaid');

        await openPos(page);
        await openOrderFromTab(page, 'Unpaid', occupied?.order?.code ?? '');
        await expect(page.locator('#cart-products-table')).toContainText(first.name);

        const reopenedCustomer = await readPosCustomer(page);
        expect(reopenedCustomer.id).toBe(customerAtSale.id);
        expect(reopenedCustomer.group_id).toBe(customerAtSale.group_id);
        expect(reopenedCustomer.minimal_credit_payment).toBe(40);

        const layawayProbe = await page.evaluate(() => {
            const order = (window as unknown as {
                POS?: {
                    resolveMinimalCreditPaymentPercent?: (order: unknown) => number;
                    order?: {
                        getValue?: () => {
                            total?: number;
                            customer?: { group?: { minimal_credit_payment?: number } };
                        };
                    };
                };
            }).POS;
            const current = order?.order?.getValue?.();
            const percent = order?.resolveMinimalCreditPaymentPercent
                ? order.resolveMinimalCreditPaymentPercent(current)
                : Number(current?.customer?.group?.minimal_credit_payment ?? NaN);
            const total = Number(current?.total ?? NaN);

            return {
                percent,
                expected: Number.isFinite(percent) && Number.isFinite(total) ? (total * percent) / 100 : null,
            };
        });
        expect(layawayProbe.percent).toBe(40);
        expect(layawayProbe.expected).toBeGreaterThan(0);

        await payInFull(page);

        await page.goto('/dashboard/zash-dine/floor');
        await expectTableStatus(page, created.tableName, 'Available');

        await openPos(page);
        await addProduct(page, third.name);
        await payPartial(page, created.tableName);
        await page.goto('/dashboard/zash-dine/floor');
        await expectTableStatus(page, created.tableName, 'Occupied');
        const partial = await readFloorTable(page, created.tableName);
        expect(partial?.order?.payment_status).toBe('partially_paid');
        orderId = partial?.current_order_id ?? orderId;

        await openPos(page);
        await addProduct(page, products[3].name);
        const payTable = await createAreaAndTable(page, 'E2E delete');
        try {
            await saveUnpaidDineIn(page, payTable.tableName);
            await page.goto('/dashboard/zash-dine/floor');
            await expectTableStatus(page, payTable.tableName, 'Occupied');
            const deleting = await readFloorTable(page, payTable.tableName);
            expect(deleting?.order?.code).toBeTruthy();
            await deleteOrderFromList(page, deleting?.order?.code ?? '');
            await page.goto('/dashboard/zash-dine/floor');
            await expectTableStatus(page, payTable.tableName, 'Available');
        } finally {
            await page.goto('/dashboard');
            const leftover = await readFloorTable(page, payTable.tableName);
            await deleteAreaAndTable(page, payTable.areaId, payTable.tableId, leftover?.current_order_id);
        }
    } finally {
        if (restoredGroupId !== null && restoredMinimalPayment !== null) {
            await setCustomerGroupMinimalCreditPayment(page, restoredGroupId, restoredMinimalPayment).catch(() => undefined);
        }

        await page.goto('/dashboard');
        await deleteAreaAndTable(page, created.areaId, created.tableId, orderId);
    }
});
