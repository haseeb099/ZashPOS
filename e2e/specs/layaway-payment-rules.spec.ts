import { expect, test } from '@playwright/test';
import { authState } from '../helpers/auth';
import {
    createAreaAndTable,
    deleteAreaAndTable,
    listProducts,
    setCustomerGroupMinimalCreditPayment,
} from '../helpers/api';
import { expectTableStatus, readFloorTable } from '../helpers/floor';
import {
    addProduct,
    expectNoOrderWrites,
    mutatePosCustomerGroupRule,
    openLayawayPopup,
    openOrderFromTab,
    openPos,
    readDisplayedLayawayMinimum,
    readPosCustomer,
    saveUnpaidDineIn,
} from '../helpers/pos';

test.use({ storageState: authState('admin') });

test('layaway enforces nonzero group minimums and rejects invalid payment rules', async ({ page }) => {
    test.setTimeout(360000);
    const created = await createAreaAndTable(page, 'E2E layaway');
    const products = await listProducts(page);
    const [first] = products;
    let orderId: number | null = null;
    let restoredGroupId: number | null = null;
    let restoredMinimalPayment: number | null = null;

    try {
        await openPos(page);
        const customerAtSale = await readPosCustomer(page);
        expect(customerAtSale.id).not.toBeNull();
        expect(customerAtSale.group_id).not.toBeNull();
        restoredGroupId = customerAtSale.group_id;
        restoredMinimalPayment = customerAtSale.minimal_credit_payment;
        await setCustomerGroupMinimalCreditPayment(page, customerAtSale.group_id as number, 40);

        await addProduct(page, first.name);
        await saveUnpaidDineIn(page, created.tableName);

        const occupied = await readFloorTable(page, created.tableName);
        orderId = occupied?.current_order_id ?? null;
        expect(occupied?.order?.payment_status).toBe('unpaid');
        expect(occupied?.order?.code).toBeTruthy();

        await openPos(page);
        await openOrderFromTab(page, 'Unpaid', occupied?.order?.code ?? '');
        await expect(page.locator('#cart-products-table')).toContainText(first.name);

        const reopened = await readPosCustomer(page);
        expect(reopened.id).toBe(customerAtSale.id);
        expect(reopened.minimal_credit_payment).toBe(40);

        const orderTotal = await page.evaluate(() => {
            const order = (window as unknown as { POS?: { order?: { getValue?: () => { total?: number } } } }).POS?.order?.getValue?.();

            return Number(order?.total ?? 0);
        });
        const expectedMinimum = (orderTotal * 40) / 100;
        expect(expectedMinimum).toBeGreaterThan(0);

        const layaway = await openLayawayPopup(page);
        const displayedMinimum = await readDisplayedLayawayMinimum(page);
        expect(displayedMinimum).toBeCloseTo(expectedMinimum, 1);

        await expectNoOrderWrites(page, async () => {
            await layaway.locator('input[name="total_instalments"], [name="total_instalments"] input').first().fill('1');
            await expect(layaway.locator('input[name="amount"], [name="amount"] input').first()).toBeVisible({ timeout: 10000 });
            const amount = layaway.locator('input[name="amount"], [name="amount"] input').first();
            await amount.fill('0.01');
            const date = layaway.locator('input[name="date"], [name="date"] input').first();
            if (await date.count()) {
                const today = new Date().toISOString().slice(0, 10);
                await date.fill(today);
            }
            await layaway.getByRole('button', { name: 'Proceed' }).click();
            await expect(page.locator('#snack-wrapper')).toContainText(/less than what is expected|minimum payment|Unable to process/i);
            await expect(layaway).toBeVisible();
        });

        const customerAfterReject = await readPosCustomer(page);
        expect(customerAfterReject.id).toBe(customerAtSale.id);
        expect(customerAfterReject.minimal_credit_payment).toBe(40);

        await layaway.getByRole('button', { name: 'Skip Instalments' }).click();
        const initialPayment = page.locator('#confirm-popup').filter({ hasText: /Initial Payment/i });
        await expect(initialPayment).toBeVisible({ timeout: 15000 });
        await initialPayment.getByRole('button', { name: 'Yes' }).click();
        await expect(page.locator('#ns-payment-popup')).toBeHidden({ timeout: 30000 });

        const customerAfterLayaway = await readPosCustomer(page).catch(() => null);
        if (customerAfterLayaway?.id) {
            expect(customerAfterLayaway.id).toBe(customerAtSale.id);
        }

        await page.goto('/dashboard/zash-dine/floor');
        await expectTableStatus(page, created.tableName, 'Occupied');
        const afterLayaway = await readFloorTable(page, created.tableName);
        expect(afterLayaway?.order?.payment_status).toBe('partially_paid');
        orderId = afterLayaway?.current_order_id ?? orderId;
    } finally {
        if (restoredGroupId !== null && restoredMinimalPayment !== null) {
            await setCustomerGroupMinimalCreditPayment(page, restoredGroupId, restoredMinimalPayment).catch(() => undefined);
        }

        await page.goto('/dashboard');
        await deleteAreaAndTable(page, created.areaId, created.tableId, orderId);
    }
});

test('invalid payment rules fail closed without writes or customer substitution', async ({ page }) => {
    test.setTimeout(240000);
    const created = await createAreaAndTable(page, 'E2E bad rules');
    const products = await listProducts(page);
    const [product] = products;
    let orderId: number | null = null;
    let restoredGroupId: number | null = null;
    let restoredMinimalPayment: number | null = null;

    try {
        await openPos(page);
        const customerAtSale = await readPosCustomer(page);
        restoredGroupId = customerAtSale.group_id;
        restoredMinimalPayment = customerAtSale.minimal_credit_payment;
        await setCustomerGroupMinimalCreditPayment(page, customerAtSale.group_id as number, 25);
        await addProduct(page, product.name);
        await saveUnpaidDineIn(page, created.tableName);

        const occupied = await readFloorTable(page, created.tableName);
        orderId = occupied?.current_order_id ?? null;

        await openPos(page);
        await openOrderFromTab(page, 'Unpaid', occupied?.order?.code ?? '');
        const before = await readPosCustomer(page);
        expect(before.id).toBe(customerAtSale.id);
        expect(before.minimal_credit_payment).toBe(25);

        for (const malformed of ['', 'abc', 'nan', 'missing-group'] as const) {
            await mutatePosCustomerGroupRule(page, malformed);
            const during = await readPosCustomer(page);
            expect(during.id).toBe(customerAtSale.id);

            await expectNoOrderWrites(page, async () => {
                await page.locator('#pay-button').click();
                await expect(page.locator('#snack-wrapper')).toContainText(/payment rules are unavailable or invalid|payment rules unavailable/i, {
                    timeout: 10000,
                }).catch(async () => {
                    const payment = page.locator('#ns-payment-popup');
                    if (await payment.isVisible().catch(() => false)) {
                        await expect(payment.getByText(/Payment rules unavailable/i)).toBeVisible();
                        await expect(payment.getByRole('button', { name: /Layaway/ })).toHaveCount(0);
                        await payment.locator('.ns-close-button:visible').click();
                        await expect(payment).toBeHidden({ timeout: 15000 });
                    } else {
                        throw new Error(`Malformed rule ${String(malformed)} did not block payment.`);
                    }
                });
            });

            const after = await readPosCustomer(page);
            expect(after.id).toBe(customerAtSale.id);
        }

        await page.route('**/api/pos/orders/**', async (route) => {
            const response = await route.fetch();
            const body = await response.json();
            if (body?.customer) {
                body.customer.group = { id: body.customer.group_id ?? 1, minimal_credit_payment: '' };
            }
            await route.fulfill({ response, json: body });
        });
        await page.route('**/api/customers/**/group', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: 'null' });
        });

        await openPos(page);
        await page.getByRole('button', { name: 'Orders' }).click();
        await page.getByText('Unpaid', { exact: true }).click();
        const unpaid = page.locator('.ns-box').filter({ hasText: 'Unpaid' });
        await unpaid.locator('[data-order-id]').filter({ hasText: occupied?.order?.code ?? '' }).getByRole('button', { name: 'Open' }).click();
        await expect(page.locator('#cart-products-table')).toContainText(product.name);

        await expectNoOrderWrites(page, async () => {
            await page.locator('#pay-button').click();
            const payment = page.locator('#ns-payment-popup');
            const snack = page.locator('#snack-wrapper');
            await expect.poll(async () => {
                return (await payment.isVisible().catch(() => false)) || (await snack.isVisible().catch(() => false));
            }).toBe(true);

            if (await payment.isVisible().catch(() => false)) {
                await expect(payment.getByText(/Payment rules unavailable/i)).toBeVisible();
                await payment.locator('.ns-close-button:visible').click();
            }
        });

        const afterIntercept = await readPosCustomer(page);
        expect(afterIntercept.id).toBe(customerAtSale.id);
    } finally {
        await page.unroute('**/api/pos/orders/**').catch(() => undefined);
        await page.unroute('**/api/customers/**/group').catch(() => undefined);

        if (restoredGroupId !== null && restoredMinimalPayment !== null) {
            await setCustomerGroupMinimalCreditPayment(page, restoredGroupId, restoredMinimalPayment).catch(() => undefined);
        }

        await deleteAreaAndTable(page, created.areaId, created.tableId, orderId);
    }
});

test('valid zero group minimum still allows layaway without forcing an initial payment', async ({ page }) => {
    test.setTimeout(240000);
    const created = await createAreaAndTable(page, 'E2E zero rule');
    const products = await listProducts(page);
    const [product] = products;
    let orderId: number | null = null;
    let restoredGroupId: number | null = null;
    let restoredMinimalPayment: number | null = null;

    try {
        await openPos(page);
        const customerAtSale = await readPosCustomer(page);
        restoredGroupId = customerAtSale.group_id;
        restoredMinimalPayment = customerAtSale.minimal_credit_payment;
        await setCustomerGroupMinimalCreditPayment(page, customerAtSale.group_id as number, 0);
        await addProduct(page, product.name);
        await saveUnpaidDineIn(page, created.tableName);

        const occupied = await readFloorTable(page, created.tableName);
        orderId = occupied?.current_order_id ?? null;

        await openPos(page);
        await openOrderFromTab(page, 'Unpaid', occupied?.order?.code ?? '');
        const reopened = await readPosCustomer(page);
        expect(reopened.id).toBe(customerAtSale.id);
        expect(reopened.minimal_credit_payment).toBe(0);

        const layaway = await openLayawayPopup(page);
        const displayedMinimum = await readDisplayedLayawayMinimum(page);
        expect(displayedMinimum).toBe(0);

        await layaway.getByRole('button', { name: 'Skip Instalments' }).click();
        await expect(page.locator('#confirm-popup').filter({ hasText: /Initial Payment/i })).toBeHidden({ timeout: 5000 }).catch(() => undefined);
        await expect(page.locator('#ns-payment-popup')).toBeHidden({ timeout: 30000 });

        await page.goto('/dashboard/zash-dine/floor');
        await expectTableStatus(page, created.tableName, 'Occupied');
        const after = await readFloorTable(page, created.tableName);
        expect(['unpaid', 'partially_paid']).toContain(after?.order?.payment_status);
        orderId = after?.current_order_id ?? orderId;
    } finally {
        if (restoredGroupId !== null && restoredMinimalPayment !== null) {
            await setCustomerGroupMinimalCreditPayment(page, restoredGroupId, restoredMinimalPayment).catch(() => undefined);
        }

        await deleteAreaAndTable(page, created.areaId, created.tableId, orderId);
    }
});
