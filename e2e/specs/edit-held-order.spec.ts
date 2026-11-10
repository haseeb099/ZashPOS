import { expect, test } from '@playwright/test';
import { authState } from '../helpers/auth';
import {
    createAreaAndTable,
    deleteAreaAndTable,
    listProducts,
    setCustomerGroupMinimalCreditPayment,
} from '../helpers/api';
import { expectTableStatus, readFloorTable, tableCard } from '../helpers/floor';
import {
    addProduct,
    holdDineIn,
    holdOpenOrder,
    openHeldOrder,
    openPos,
    payInFull,
    readPosCustomer,
} from '../helpers/pos';

test.use({ storageState: authState('admin') });

test('editing a held dine-in order keeps the existing meal and appends one row', async ({ page }) => {
    test.setTimeout(240000);
    const created = await createAreaAndTable(page, 'E2E edit');
    const products = await listProducts(page);
    const [first, second] = products;
    const reference = `edit-${Date.now().toString(36)}`;
    let orderId: number | null = null;
    let restoredGroupId: number | null = null;
    let restoredMinimalPayment: number | null = null;

    try {
        await openPos(page);
        const customerAtHold = await readPosCustomer(page);
        expect(customerAtHold.id).not.toBeNull();
        expect(customerAtHold.group_id).not.toBeNull();
        restoredGroupId = customerAtHold.group_id;
        restoredMinimalPayment = customerAtHold.minimal_credit_payment;
        await setCustomerGroupMinimalCreditPayment(page, customerAtHold.group_id as number, 25);
        await addProduct(page, first.name);
        await holdDineIn(page, created.tableName, reference);

        const before = await readFloorTable(page, created.tableName);
        orderId = before?.current_order_id ?? null;
        const original = before?.items.find((item) => item.name === first.name);
        expect(original?.id).toBeTruthy();
        expect(original?.status).toBe('pending');

        await openPos(page);
        await openHeldOrder(page, reference);
        await expect(page.locator('#cart-products-table')).toContainText(first.name);
        const reopenedHeldCustomer = await readPosCustomer(page);
        expect(reopenedHeldCustomer.id).toBe(customerAtHold.id);
        expect(reopenedHeldCustomer.minimal_credit_payment).toBe(25);
        await addProduct(page, second.name);
        await holdOpenOrder(page, reference);

        await page.goto('/dashboard/zash-dine/floor');
        const card = tableCard(page, created.tableName);
        await expect(card).toContainText(first.name);
        await expect(card).toContainText(second.name);
        await expect(card).not.toContainText('Cancelled');

        const after = await readFloorTable(page, created.tableName);
        const kept = after?.items.find((item) => item.id === original?.id);
        const added = after?.items.find((item) => item.name === second.name);
        expect(kept?.status).toBe('pending');
        expect(kept?.name).toBe(first.name);
        expect(added?.status).toBe('pending');
        expect(after?.items.filter((item) => item.status === 'cancelled')).toHaveLength(0);
        expect(after?.items.filter((item) => item.name === first.name)).toHaveLength(1);
        expect(after?.items.filter((item) => item.name === second.name)).toHaveLength(1);

        await openPos(page);
        await openHeldOrder(page, reference);
        const beforePay = await readPosCustomer(page);
        expect(beforePay.id).toBe(customerAtHold.id);
        expect(beforePay.minimal_credit_payment).toBe(25);
        await payInFull(page);
        await page.goto('/dashboard/zash-dine/floor');
        await expectTableStatus(page, created.tableName, 'Available');
        orderId = null;
    } finally {
        if (restoredGroupId !== null && restoredMinimalPayment !== null) {
            await setCustomerGroupMinimalCreditPayment(page, restoredGroupId, restoredMinimalPayment).catch(() => undefined);
        }

        await deleteAreaAndTable(page, created.areaId, created.tableId, orderId);
    }
});
