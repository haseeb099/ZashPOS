import { expect, test } from '@playwright/test';
import { authState } from '../helpers/auth';
import { apiSend, createAreaAndTable, deleteAreaAndTable, expectSnack, listProducts } from '../helpers/api';
import { expectTableStatus, tableCard } from '../helpers/floor';
import { addProduct, openPos, saveUnpaidDineIn } from '../helpers/pos';

test.use({ storageState: authState('admin') });

function suffix(): string {
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`;
}

async function saveForm(page: import('@playwright/test').Page): Promise<void> {
    await page.getByRole('button', { name: 'Save' }).first().click();
}

test('duplicate table names are rejected in one area and allowed in another', async ({ page }) => {
    const token = suffix();
    const areaA = `E2E Area A ${token}`;
    const areaB = `E2E Area B ${token}`;
    const tableName = `E2E T ${token}`;

    await page.goto('/dashboard/zash-dine/areas/create');
    await page.locator('#crud-form input[name="name"]').fill(areaA);
    await saveForm(page);
    await page.waitForURL(/\/dashboard\/zash-dine\/areas\/?$/);

    await page.goto('/dashboard/zash-dine/areas/create');
    await page.locator('#crud-form input[name="name"]').fill(areaB);
    await saveForm(page);
    await page.waitForURL(/\/dashboard\/zash-dine\/areas\/?$/);

    await page.goto('/dashboard/zash-dine/tables/create');
    await expect(page.locator('select option', { hasText: /^Occupied/ })).toHaveCount(0);
    await page.locator('#crud-form input[name="name"]').fill(tableName);
    await page.locator('select[name="area_id"]').selectOption({ label: areaA });
    await page.locator('#crud-form input[name="seats"]').fill('4');
    await saveForm(page);
    await page.waitForURL(/\/dashboard\/zash-dine\/tables\/?(?:\?.*)?$/);

    await page.goto('/dashboard/zash-dine/tables/create');
    await page.locator('#crud-form input[name="name"]').fill(tableName);
    await page.locator('select[name="area_id"]').selectOption({ label: areaA });
    await page.locator('#crud-form input[name="seats"]').fill('2');
    await saveForm(page);
    await expectSnack(page, /already exists/i);
    await expect(page).toHaveURL(/tables\/create/);

    await page.goto('/dashboard/zash-dine/tables/create');
    await page.locator('#crud-form input[name="name"]').fill(tableName);
    await page.locator('select[name="area_id"]').selectOption({ label: areaB });
    await page.locator('#crud-form input[name="seats"]').fill('2');
    await saveForm(page);
    await page.waitForURL(/\/dashboard\/zash-dine\/tables\/?(?:\?.*)?$/);

    const listed = await apiSend(page, 'get', `/api/crud/zashdine.tables?search=${encodeURIComponent(tableName)}`);
    const rows = (listed.data as Array<{ id: number; name: string }>) ?? [];
    const first = rows.find((row) => row.name === tableName);
    expect(first?.id).toBeTruthy();

    await page.goto(`/dashboard/zash-dine/tables/edit/${first?.id}`);
    await expect(page.locator('select option', { hasText: /^Occupied/ })).toHaveCount(0);
    await expect(page.getByLabel('Status')).toHaveValue(/system-managed/i);

    for (const row of rows.filter((entry) => entry.name === tableName)) {
        await apiSend(page, 'delete', `/api/crud/zashdine.tables/${row.id}`).catch(() => undefined);
    }

    const areas = await apiSend(page, 'get', '/api/crud/zashdine.areas?per_page=100');
    for (const area of (areas.data as Array<{ id: number; name: string }>) ?? []) {
        if (area.name === areaA || area.name === areaB) {
            await apiSend(page, 'delete', `/api/crud/zashdine.areas/${area.id}`).catch(() => undefined);
        }
    }
});

test('an area with an occupied table cannot be disabled or deleted', async ({ page }) => {
    const created = await createAreaAndTable(page, 'E2E busy');
    const products = await listProducts(page);
    let orderId: number | null = null;

    try {
        await openPos(page);
        await addProduct(page, products[0].name);
        await saveUnpaidDineIn(page, created.tableName);

        await page.goto('/dashboard/zash-dine/floor');
        await expectTableStatus(page, created.tableName, 'Occupied');
        const card = tableCard(page, created.tableName);
        orderId = null;
        const code = (await card.getByText(/Order:/).textContent()) ?? '';
        expect(code).toMatch(/Order:/);

        await page.goto(`/dashboard/zash-dine/areas/edit/${created.areaId}`);
        await page.locator('select[name="status"]').selectOption({ label: 'Disabled' });
        await saveForm(page);
        await expectSnack(page, /Unable to disable an area that has occupied tables/i);

        await page.goto('/dashboard/zash-dine/areas');
        const search = page.locator('#crud-search-box input');
        await search.fill(created.areaName);
        await search.press('Enter');
        const row = page.locator('tr').filter({ hasText: created.areaName });
        await row.getByRole('button', { name: 'Options' }).click();
        const deleteItem = page.getByRole('menuitem', { name: /Delete/ });
        await expect(deleteItem).toBeVisible();
        await deleteItem.click({ force: true });
        await page.locator('#confirm-popup').getByRole('button', { name: 'Yes' }).click();
        await expectSnack(page, /Unable to delete an area that has occupied tables/i);
    } finally {
        const floor = await apiSend(page, 'get', '/api/zash-dine/floor').catch(() => null);
        const areas = ((floor?.data as { areas?: Array<{ tables?: Array<{ name: string; current_order_id?: number }> }> })?.areas) ?? [];
        for (const area of areas) {
            const table = area.tables?.find((candidate) => candidate.name === created.tableName);
            orderId = table?.current_order_id ?? orderId;
        }
        await deleteAreaAndTable(page, created.areaId, created.tableId, orderId);
    }
});
