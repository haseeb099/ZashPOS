import { expect, test, type Page } from '@playwright/test';
import { apiSend, createAreaAndTable, deleteAreaAndTable, listProducts } from '../helpers/api';
import { authState } from '../helpers/auth';
import { readFloorTable } from '../helpers/floor';
import {
    addProduct,
    holdDineIn,
    holdOpenOrder,
    openHeldOrder,
    openPos,
    saveUnpaidDineIn,
} from '../helpers/pos';
import { baseURL } from '../load-env';

async function listCategories(page: Page): Promise<Array<{ id: number; name: string }>> {
    const body = await apiSend(page, 'get', '/api/crud/ns.products-categories?per_page=50');
    const rows = (body.data as Array<Record<string, unknown>> | undefined) ?? [];

    return rows
        .map((row) => ({ id: Number(row.id), name: String(row.name ?? '') }))
        .filter((row) => row.id > 0);
}

async function productDetails(page: Page, productId: number): Promise<{
    id: number;
    name: string;
    category_id: number;
    unit_quantity_id: number;
    unit_id: number;
    sale_price: number;
}> {
    const body = await apiSend(page, 'get', `/api/products/${productId}`);
    const product = (Array.isArray(body) ? body[0] : body) as Record<string, unknown>;
    const quantitiesBody = await apiSend(page, 'get', `/api/products/${productId}/units/quantities`);
    const quantities = (Array.isArray(quantitiesBody)
        ? quantitiesBody
        : ((quantitiesBody.data as Array<Record<string, unknown>> | undefined) ?? [])) as Array<Record<string, unknown>>;
    const unit = quantities[0] ?? {};

    return {
        id: Number(product.id ?? productId),
        name: String(product.name ?? ''),
        category_id: Number(product.category_id ?? 0),
        unit_quantity_id: Number(unit.id ?? 0),
        unit_id: Number(unit.unit_id ?? 0),
        sale_price: Number(unit.sale_price ?? unit.incl_tax_sale_price ?? 0),
    };
}

async function wipeKitchens(page: Page): Promise<void> {
    const existing = await apiSend(page, 'get', '/api/crud/zashdine.kitchens?per_page=50').catch(() => ({ data: [] }));
    const rows = (existing.data as Array<Record<string, unknown>> | undefined) ?? [];
    for (const row of rows) {
        const id = Number(row.id);
        if (id > 0) {
            await apiSend(page, 'put', `/api/crud/zashdine.kitchens/${id}`, {
                name: String(row.name ?? `Kitchen ${id}`),
                general: {
                    description: String(row.description ?? ''),
                    status: 'disabled',
                    category_ids: [],
                },
            }).catch(() => undefined);
            await apiSend(page, 'delete', `/api/crud/zashdine.kitchens/${id}`).catch(() => undefined);
        }
    }
}

async function createKitchen(page: Page, name: string, categoryIds: number[]): Promise<number> {
    const body = await apiSend(page, 'post', '/api/crud/zashdine.kitchens', {
        name,
        general: {
            description: 'Playwright kitchen',
            status: 'enabled',
            category_ids: categoryIds,
        },
    });
    const data = body.data as Record<string, unknown> | undefined;
    const entry = data?.entry as Record<string, unknown> | undefined;

    return Number(entry?.id);
}

async function deleteKitchen(page: Page, kitchenId: number): Promise<void> {
    await apiSend(page, 'delete', `/api/crud/zashdine.kitchens/${kitchenId}`).catch(() => undefined);
}

async function defaultCustomerId(page: Page): Promise<number> {
    const body = await apiSend(page, 'get', '/api/customers');
    const rows = Array.isArray(body)
        ? body
        : ((body.data as Array<Record<string, unknown>> | undefined) ?? []);
    const first = rows[0] as Record<string, unknown> | undefined;
    const id = Number(first?.id ?? 0);
    expect(id, 'need a customer for unpaid dine-in').toBeGreaterThan(0);

    return id;
}

async function createUnpaidDineInOrder(
    page: Page,
    tableId: number,
    customerId: number,
    products: Array<{ id: number; name: string; unit_quantity_id: number; unit_id: number; sale_price: number }>,
): Promise<number> {
    const payload = {
        customer_id: customerId,
        type: { identifier: 'dine-in' },
        zash_dine_table_id: tableId,
        discount: 0,
        discount_type: 'percentage',
        discount_percentage: 0,
        coupons: [],
        payments: [],
        addresses: {
            shipping: {},
            billing: {},
        },
        subtotal: products.reduce((sum, product) => sum + Number(product.sale_price), 0),
        total: products.reduce((sum, product) => sum + Number(product.sale_price), 0),
        tendered: 0,
        note: 'Playwright kitchen order',
        products: products.map((product) => ({
            product_id: product.id,
            name: product.name,
            quantity: 1,
            unit_price: product.sale_price,
            unit_quantity_id: product.unit_quantity_id,
            unit_id: product.unit_id,
            discount: 0,
            discount_type: 'percentage',
            discount_percentage: 0,
            tax_type: 'inclusive',
            tax_group_id: 1,
        })),
    };

    const body = await apiSend(page, 'post', '/api/orders', payload);
    const data = body.data as Record<string, unknown> | undefined;
    const order = (data?.order ?? body.order ?? data) as Record<string, unknown> | undefined;
    const orderId = Number(order?.id ?? 0);
    expect(orderId, `order create failed: ${JSON.stringify(body)}`).toBeGreaterThan(0);

    return orderId;
}

test.describe('ZashDine kitchen workflow', () => {
    test('routes meals to kitchens, isolates filters, and updates status without losing rejected charges', async ({ browser }) => {
        test.setTimeout(360000);
        const admin = await browser.newContext({ storageState: authState('admin'), baseURL: baseURL() });
        const chef = await browser.newContext({ storageState: authState('chef'), baseURL: baseURL() });
        const adminPage = await admin.newPage();
        const chefPage = await chef.newPage();
        const created = await createAreaAndTable(adminPage, 'E2E kitchen');
        const products = await listProducts(adminPage);
        const categories = await listCategories(adminPage);
        expect(categories.length).toBeGreaterThanOrEqual(2);

        let kitchenA = 0;
        let kitchenB = 0;
        let orderId: number | null = null;

        try {
            const detailed = [];
            for (const product of products.slice(0, 20)) {
                detailed.push(await productDetails(adminPage, product.id));
            }

            const productA = detailed.find((row) => row.category_id > 0 && row.unit_quantity_id > 0);
            const productB = detailed.find((row) => (
                row.category_id > 0
                && row.unit_quantity_id > 0
                && row.category_id !== productA?.category_id
            ));
            expect(productA, 'need a product with a category').toBeTruthy();
            expect(productB, 'need a second product in a different category').toBeTruthy();

            await wipeKitchens(adminPage);
            kitchenA = await createKitchen(adminPage, `Grill ${Date.now().toString(36)}`, [productA!.category_id]);
            kitchenB = await createKitchen(adminPage, `Bar ${Date.now().toString(36)}`, [productB!.category_id]);
            expect(kitchenA).toBeGreaterThan(0);
            expect(kitchenB).toBeGreaterThan(0);

            const customerId = await defaultCustomerId(adminPage);
            orderId = await createUnpaidDineInOrder(adminPage, created.tableId, customerId, [productA!, productB!]);

            await chefPage.goto('/dashboard/zash-dine/kitchen');
            await expect(chefPage.getByRole('heading', { name: 'Kitchen' })).toBeVisible();
            await expect(chefPage.locator('[data-zash-dine-kitchen-screen]')).toBeVisible({ timeout: 30000 });

            await chefPage.locator('#zash-dine-kitchen-filter').selectOption(String(kitchenA));
            await expect.poll(async () => {
                return chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenA}"]`).count();
            }, { timeout: 20000 }).toBeGreaterThan(0);
            await expect(chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenB}"]`)).toHaveCount(0);

            await chefPage.locator('#zash-dine-kitchen-filter').selectOption(String(kitchenB));
            await expect.poll(async () => {
                return chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenB}"]`).count();
            }, { timeout: 20000 }).toBeGreaterThan(0);
            await expect(chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenA}"]`)).toHaveCount(0);

            const ticketB = chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenB}"]`).first();
            await ticketB.getByRole('button', { name: 'Start preparing', exact: true }).click();
            await expect.poll(async () => ticketB.getAttribute('data-zash-dine-ticket-status'), { timeout: 15000 })
                .toBe('preparing');
            await ticketB.getByRole('button', { name: 'Mark ready', exact: true }).click();
            await expect.poll(async () => ticketB.getAttribute('data-zash-dine-ticket-status'), { timeout: 15000 })
                .toBe('ready');

            await chefPage.locator('#zash-dine-kitchen-filter').selectOption(String(kitchenA));
            const ticketA = chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenA}"]`).first();
            await ticketA.getByRole('button', { name: 'Reject', exact: true }).click();
            await expect.poll(async () => ticketA.getAttribute('data-zash-dine-ticket-status'), { timeout: 15000 })
                .toBe('rejected');

            const floor = await apiSend(adminPage, 'get', '/api/zash-dine/floor');
            const areas = ((floor.data as Record<string, unknown>)?.areas ?? []) as Array<Record<string, unknown>>;
            const table = areas
                .flatMap((area) => (area.tables as Array<Record<string, unknown>> | undefined) ?? [])
                .find((row) => String(row.name) === created.tableName);
            orderId = table?.current_order_id ? Number(table.current_order_id) : orderId;
            const items = (table?.items as Array<Record<string, unknown>> | undefined) ?? [];
            expect(items.some((item) => item.status === 'rejected')).toBeTruthy();
            expect(items.some((item) => item.status === 'cancelled')).toBeFalsy();
        } finally {
            await deleteKitchen(adminPage, kitchenA);
            await deleteKitchen(adminPage, kitchenB);
            await deleteAreaAndTable(adminPage, created.areaId, created.tableId, orderId);
            await chef.close();
            await admin.close();
        }
    });

    test('POS dine-in create and edit keep kitchen meal identity', async ({ browser }) => {
        test.setTimeout(360000);
        const admin = await browser.newContext({ storageState: authState('admin'), baseURL: baseURL() });
        const chef = await browser.newContext({ storageState: authState('chef'), baseURL: baseURL() });
        const adminPage = await admin.newPage();
        const chefPage = await chef.newPage();
        const created = await createAreaAndTable(adminPage, 'E2E kitchen POS');
        const products = await listProducts(adminPage);

        let kitchenA = 0;
        let orderId: number | null = null;

        try {
            const detailed = [];
            for (const product of products.slice(0, 20)) {
                detailed.push(await productDetails(adminPage, product.id));
            }

            const productA = detailed.find((row) => row.category_id > 0 && row.unit_quantity_id > 0);
            const productB = detailed.find((row) => (
                row.id !== productA?.id
                && row.category_id === productA?.category_id
                && row.unit_quantity_id > 0
            ));
            expect(productA, 'need a product with a category').toBeTruthy();
            expect(productB, 'need a second product in the same category').toBeTruthy();

            await wipeKitchens(adminPage);
            kitchenA = await createKitchen(adminPage, `POS Grill ${Date.now().toString(36)}`, [productA!.category_id]);
            expect(kitchenA).toBeGreaterThan(0);

            const reference = `kitchen-pos-${Date.now().toString(36)}`;
            await openPos(adminPage);
            await addProduct(adminPage, productA!.name);
            await holdDineIn(adminPage, created.tableName, reference);

            const floorBefore = await readFloorTable(adminPage, created.tableName);
            orderId = floorBefore?.current_order_id ?? null;
            expect(orderId).toBeTruthy();
            const original = floorBefore?.items.find((item) => item.name === productA!.name);
            expect(original?.id).toBeTruthy();
            expect(original?.status).toBe('pending');

            await chefPage.goto('/dashboard/zash-dine/kitchen');
            await expect(chefPage.locator('[data-zash-dine-kitchen-screen]')).toBeVisible({ timeout: 30000 });
            await chefPage.locator('#zash-dine-kitchen-filter').selectOption(String(kitchenA));
            await expect.poll(async () => {
                return chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenA}"]`).count();
            }, { timeout: 20000 }).toBeGreaterThan(0);
            await expect(chefPage.locator(`[data-zash-dine-ticket-id="${original!.id}"]`)).toBeVisible();

            await openPos(adminPage);
            await openHeldOrder(adminPage, reference);
            await expect(adminPage.locator('#cart-products-table')).toContainText(productA!.name);
            await addProduct(adminPage, productB!.name);
            await holdOpenOrder(adminPage, reference);

            const floorAfter = await readFloorTable(adminPage, created.tableName);
            const kept = floorAfter?.items.find((item) => item.id === original?.id);
            const added = floorAfter?.items.find((item) => item.name === productB!.name);
            expect(kept?.status).toBe('pending');
            expect(kept?.name).toBe(productA!.name);
            expect(added?.status).toBe('pending');
            expect(floorAfter?.items.filter((item) => item.name === productA!.name && item.status !== 'cancelled')).toHaveLength(1);
            expect(floorAfter?.items.filter((item) => item.name === productB!.name && item.status !== 'cancelled')).toHaveLength(1);

            expect(added?.id).toBeTruthy();
            await chefPage.locator('#zash-dine-kitchen-filter').selectOption(String(kitchenA));
            await chefPage.getByRole('button', { name: 'Refresh now' }).click();
            await expect.poll(async () => {
                return chefPage.locator(`[data-zash-dine-ticket-id="${original!.id}"]`).count();
            }, { timeout: 15000 }).toBe(1);
            await expect.poll(async () => {
                return chefPage.locator(`[data-zash-dine-ticket-id="${added!.id}"]`).count();
            }, { timeout: 15000 }).toBe(1);
            await expect(
                chefPage.locator(`[data-zash-dine-ticket-id="${original!.id}"]`)
            ).toHaveAttribute('data-zash-dine-ticket-status', 'pending');
        } finally {
            await deleteKitchen(adminPage, kitchenA);
            await deleteAreaAndTable(adminPage, created.areaId, created.tableId, orderId);
            await chef.close();
            await admin.close();
        }
    });

    test('waiter is denied kitchen screen while chef can open it', async ({ browser }) => {
        const waiter = await browser.newContext({ storageState: authState('waiter'), baseURL: baseURL() });
        const chef = await browser.newContext({ storageState: authState('chef'), baseURL: baseURL() });
        const waiterPage = await waiter.newPage();
        const chefPage = await chef.newPage();

        try {
            await waiterPage.goto('/dashboard/zash-dine/kitchen');
            await expect(waiterPage.getByRole('heading', { name: 'Not Enough Permissions' })).toBeVisible();

            await chefPage.goto('/dashboard/zash-dine/kitchen');
            await expect(chefPage.getByRole('heading', { name: 'Kitchen' })).toBeVisible();
            await expect(chefPage.locator('[data-zash-dine-kitchen-screen]')).toBeVisible({ timeout: 30000 });
            await expect(chefPage.getByRole('button', { name: 'Refresh now' })).toBeVisible();
        } finally {
            await waiter.close();
            await chef.close();
        }
    });
});
