import { expect, test, type Page, type Route } from '@playwright/test';
import { authState } from '../helpers/auth';
import { createAreaAndTable, deleteAreaAndTable, listProducts } from '../helpers/api';
import { readFloorTable, tableCard } from '../helpers/floor';
import { dismissTablePickerWithEscape, pickTable, tablePicker } from '../helpers/pick-table';
import { addProduct, openPos } from '../helpers/pos';
import { baseURL } from '../load-env';

async function openDineInPicker(page: Page, productName: string): Promise<void> {
    await openPos(page);
    await addProduct(page, productName);
    await page.locator('#pay-button').click();
    await expect(page.getByText('Define The Order Type')).toBeVisible();
    await page.getByText('Dine-in', { exact: true }).click();
    await expect(tablePicker(page)).toBeVisible({ timeout: 20000 });
}

async function expectCartStillHasProducts(page: Page): Promise<void> {
    await expect(page.locator('#cart-products-table')).not.toContainText('No products added');
}

test.describe('ZashDine Vue popups', () => {
    test('table picker handles empty list, load failure retry, race, and dismissals without writes', async ({ browser }) => {
        test.setTimeout(300000);
        const admin = await browser.newContext({ storageState: authState('admin'), baseURL: baseURL() });
        const page = await admin.newPage();
        const created = await createAreaAndTable(page, 'E2E popup');
        const products = await listProducts(page);
        let orderId: number | null = null;
        let availableCalls = 0;

        try {
            await page.route('**/api/zash-dine/tables/available', async (route: Route) => {
                availableCalls += 1;

                if (availableCalls === 1) {
                    await route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify({ data: { tables: [] } }),
                    });
                    return;
                }

                if (availableCalls === 2) {
                    await route.fulfill({
                        status: 500,
                        contentType: 'application/json',
                        body: JSON.stringify({ message: 'Forced available-tables failure' }),
                    });
                    return;
                }

                if (availableCalls === 3) {
                    await route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify({
                            data: {
                                tables: [{
                                    id: created.tableId,
                                    name: created.tableName,
                                    seats: 4,
                                    area_id: created.areaId,
                                    area_name: created.areaName,
                                }],
                            },
                        }),
                    });
                    return;
                }

                // Confirm-time re-check and later refreshes: table no longer available.
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ data: { tables: [] } }),
                });
            });

            await openDineInPicker(page, products[0].name);
            const picker = tablePicker(page);
            await expect(picker.getByText(/No available tables/i)).toBeVisible();
            await picker.getByRole('button', { name: 'Retry', exact: true }).click();
            await expect(picker.getByRole('alert')).toContainText(/Forced available-tables failure|Unable to load available tables/i);
            await picker.getByRole('button', { name: 'Retry', exact: true }).click();
            await expect(picker.getByRole('button', { name: new RegExp(created.tableName) })).toBeVisible();
            await picker.getByRole('button', { name: new RegExp(created.tableName) }).click();
            await picker.getByRole('button', { name: 'Use this table', exact: true }).click();
            await expect(picker.locator('[data-zash-dine-notice="race"]')).toContainText(/no longer available|No available tables/i);
            await expect(picker.getByRole('button', { name: 'Use this table', exact: true })).toBeDisabled();

            await dismissTablePickerWithEscape(page);
            await expect(page.getByText('Define The Order Type')).toBeVisible();
            await expectCartStillHasProducts(page);

            await page.getByText('Dine-in', { exact: true }).click();
            await expect(picker).toBeVisible();
            await picker.getByRole('button', { name: 'Cancel', exact: true }).click();
            await expect(picker).toBeHidden();
            await expectCartStillHasProducts(page);

            await page.getByText('Dine-in', { exact: true }).click();
            await expect(picker).toBeVisible();
            await picker.getByRole('button', { name: 'Close', exact: true }).click();
            await expect(picker).toBeHidden();
            await expectCartStillHasProducts(page);

            const floor = await readFloorTable(page, created.tableName);
            expect(floor?.status === 'available' || floor?.current_order_id == null).toBeTruthy();
            orderId = floor?.current_order_id ?? null;
        } finally {
            await page.unroute('**/api/zash-dine/tables/available').catch(() => undefined);
            await deleteAreaAndTable(page, created.areaId, created.tableId, orderId);
            await admin.close();
        }
    });

    test('tablet viewports keep popup controls reachable with long content', async ({ browser }) => {
        test.setTimeout(300000);
        const viewports = [
            { width: 768, height: 1024 },
            { width: 1024, height: 768 },
        ];
        const longMeal = `Extra-long meal name ${'Pad-Thai-Cashew-Chili-'.repeat(6)}`;
        const longNote = `Guest note ${'sesame-peanut-gluten-dairy-'.repeat(8)}`;
        const admin = await browser.newContext({ storageState: authState('admin'), baseURL: baseURL() });
        const waiter = await browser.newContext({ storageState: authState('waiter'), baseURL: baseURL() });
        const adminPage = await admin.newPage();
        const page = await waiter.newPage();
        const created = await createAreaAndTable(adminPage, 'E2E tablet');
        const products = await listProducts(adminPage);
        let orderId: number | null = null;

        const assertReachable = async (popup: ReturnType<Page['locator']>, control: ReturnType<Page['locator']>) => {
            const box = await control.boundingBox();
            const shell = await popup.boundingBox();
            expect(box, 'control should have a box').toBeTruthy();
            expect(shell, 'popup shell should have a box').toBeTruthy();
            const viewport = page.viewportSize();
            expect(viewport).toBeTruthy();
            expect(shell!.width).toBeLessThanOrEqual(viewport!.width);
            expect(shell!.height).toBeLessThanOrEqual(viewport!.height);
            expect(box!.y + box!.height).toBeLessThanOrEqual(viewport!.height + 1);
            expect(box!.x + box!.width).toBeLessThanOrEqual(viewport!.width + 1);
            await expect(control).toBeVisible();
        };

        try {
            await page.route('**/api/zash-dine/tables/available', async (route: Route) => {
                const tables = Array.from({ length: 14 }, (_, index) => ({
                    id: created.tableId + index,
                    name: index === 0
                        ? created.tableName
                        : `Long table label ${'Banquet-Window-Seat-'.repeat(3)} ${index + 1}`,
                    seats: 4,
                    area_id: created.areaId,
                    area_name: `Long area ${'Garden-Patio-'.repeat(3)}`,
                }));
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ data: { tables } }),
                });
            });

            await openPos(page);
            await addProduct(page, products[0].name);

            for (const viewport of viewports) {
                await page.setViewportSize(viewport);
                if (! await page.getByText('Define The Order Type').isVisible().catch(() => false)) {
                    await page.locator('#pay-button').click();
                    await expect(page.getByText('Define The Order Type')).toBeVisible();
                }
                await page.getByText('Dine-in', { exact: true }).click();
                const picker = tablePicker(page);
                await expect(picker).toBeVisible({ timeout: 20000 });
                await expect(picker.getByRole('button', { name: new RegExp(created.tableName) })).toBeVisible();
                await assertReachable(picker, picker.getByRole('button', { name: 'Use this table', exact: true }));
                await assertReachable(picker, picker.locator('.ns-close-button'));
                await page.keyboard.press('Escape');
                await expect(picker).toBeHidden();
            }

            await page.unroute('**/api/zash-dine/tables/available').catch(() => undefined);
            await page.getByText('Dine-in', { exact: true }).click();
            await pickTable(page, created.tableName);
            await expect(page.locator('#ns-payment-popup')).toBeVisible();
            await page.getByRole('button', { name: /Save As Unpaid/ }).click();
            await page.locator('#confirm-popup').getByRole('button', { name: 'Yes' }).click();
            await expect(page.locator('#ns-payment-popup')).toBeHidden({ timeout: 30000 });

            await page.goto('/dashboard/zash-dine/floor');
            const before = await readFloorTable(page, created.tableName);
            orderId = before?.current_order_id ?? null;
            const card = tableCard(page, created.tableName);

            for (const viewport of viewports) {
                await page.setViewportSize(viewport);
                await card.getByRole('button', { name: 'Add note' }).click();
                const notePopup = page.locator('#zash-dine-note-popup');
                await expect(notePopup).toBeVisible();
                await notePopup.evaluate((el, meal) => {
                    const label = el.querySelector('p');
                    if (label) {
                        label.textContent = meal;
                    }
                }, longMeal);
                await notePopup.locator('textarea').fill(longNote);
                await assertReachable(notePopup, notePopup.getByRole('button', { name: 'Save note', exact: true }));
                await notePopup.locator('.ns-close-button').click();
                await expect(notePopup).toBeHidden();

                await card.getByRole('button', { name: 'Cancel meal' }).click();
                const cancelPopup = page.locator('#zash-dine-cancel-meal-popup');
                await expect(cancelPopup).toBeVisible();
                await cancelPopup.evaluate((el, meal) => {
                    const labels = el.querySelectorAll('p');
                    if (labels[1]) {
                        labels[1].textContent = meal;
                    }
                }, longMeal);
                await assertReachable(cancelPopup, cancelPopup.getByRole('button', { name: 'Cancel meal', exact: true }));
                await page.keyboard.press('Escape');
                await expect(cancelPopup).toBeHidden();
            }
        } finally {
            await page.unroute('**/api/zash-dine/tables/available').catch(() => undefined);
            await deleteAreaAndTable(adminPage, created.areaId, created.tableId, orderId);
            await waiter.close();
            await admin.close();
        }
    });

    test('note and cancel popups persist, dismiss without save, and keep errors actionable', async ({ browser }) => {
        test.setTimeout(300000);
        const admin = await browser.newContext({ storageState: authState('admin'), baseURL: baseURL() });
        const waiter = await browser.newContext({ storageState: authState('waiter'), baseURL: baseURL() });
        const adminPage = await admin.newPage();
        const page = await waiter.newPage();
        const created = await createAreaAndTable(adminPage, 'E2E noteui');
        const products = await listProducts(adminPage);
        const note = `Extra long allergy note ${'peanuts-sesame-gluten-'.repeat(4)}${Date.now().toString(36)}`;
        let orderId: number | null = null;
        let notePuts = 0;
        let cancelPosts = 0;

        try {
            await openPos(page);
            await addProduct(page, products[0].name);
            await page.locator('#pay-button').click();
            await page.getByText('Dine-in', { exact: true }).click();
            await tablePicker(page).getByRole('button', { name: new RegExp(created.tableName) }).click();
            await tablePicker(page).getByRole('button', { name: 'Use this table', exact: true }).click();
            await expect(page.locator('#ns-payment-popup')).toBeVisible();
            await page.getByRole('button', { name: /Save As Unpaid/ }).click();
            await page.locator('#confirm-popup').getByRole('button', { name: 'Yes' }).click();
            await expect(page.locator('#ns-payment-popup')).toBeHidden({ timeout: 30000 });

            await page.goto('/dashboard/zash-dine/floor');
            const before = await readFloorTable(page, created.tableName);
            orderId = before?.current_order_id ?? null;
            const card = tableCard(page, created.tableName);

            await card.getByRole('button', { name: 'Add note' }).click();
            const notePopup = page.locator('#zash-dine-note-popup');
            await expect(notePopup).toBeVisible();
            await notePopup.locator('textarea').fill('temporary draft');
            await notePopup.locator('.ns-box-footer').getByRole('button', { name: 'Cancel', exact: true }).click();
            await expect(notePopup).toBeHidden();
            expect((await readFloorTable(page, created.tableName))?.items[0]?.note ?? null).toBeNull();

            await page.route('**/api/zash-dine/order-items/*/note', async (route: Route) => {
                notePuts += 1;
                if (notePuts === 1) {
                    await route.fulfill({
                        status: 422,
                        contentType: 'application/json',
                        body: JSON.stringify({ message: 'Forced note failure' }),
                    });
                    return;
                }
                await route.continue();
            });

            await card.getByRole('button', { name: 'Add note' }).click();
            await expect(notePopup).toBeVisible();
            await notePopup.locator('textarea').fill(note);
            await notePopup.getByRole('button', { name: 'Save note', exact: true }).click();
            await expect(notePopup.getByRole('alert')).toContainText(/Forced note failure/i);
            await expect(notePopup.getByRole('button', { name: 'Save note', exact: true })).toBeEnabled();
            await notePopup.getByRole('button', { name: 'Save note', exact: true }).click();
            await expect(notePopup).toBeHidden({ timeout: 15000 });
            await expect.poll(async () => card.getByText(note.slice(0, 24)).count(), { timeout: 12000 }).toBeGreaterThan(0);

            await page.route('**/api/zash-dine/order-items/*/cancel', async (route: Route) => {
                cancelPosts += 1;
                if (cancelPosts === 1) {
                    await route.fulfill({
                        status: 422,
                        contentType: 'application/json',
                        body: JSON.stringify({ message: 'Forced cancel failure' }),
                    });
                    return;
                }
                await route.continue();
            });

            await card.getByRole('button', { name: 'Cancel meal' }).click();
            const cancelPopup = page.locator('#zash-dine-cancel-meal-popup');
            await expect(cancelPopup).toBeVisible();
            await cancelPopup.getByRole('button', { name: 'Cancel meal', exact: true }).click();
            await expect(cancelPopup.getByRole('alert')).toContainText(/Forced cancel failure/i);
            await expect(cancelPopup.getByRole('button', { name: 'Cancel meal', exact: true })).toBeEnabled();
            await cancelPopup.locator('button', { hasText: /^Keep meal$/ }).click();
            await expect(cancelPopup).toBeHidden();
            expect((await readFloorTable(page, created.tableName))?.items[0]?.status).not.toBe('cancelled');
            expect(notePuts).toBeGreaterThanOrEqual(2);
            expect(cancelPosts).toBe(1);
        } finally {
            await page.unroute('**/api/zash-dine/order-items/*/note').catch(() => undefined);
            await page.unroute('**/api/zash-dine/order-items/*/cancel').catch(() => undefined);
            await deleteAreaAndTable(adminPage, created.areaId, created.tableId, orderId);
            await waiter.close();
            await admin.close();
        }
    });
});
