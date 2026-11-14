import { expect, test } from '@playwright/test';
import { authState } from '../helpers/auth';
import {
    apiSend,
    createAreaAndTable,
    deleteAreaAndTable,
    listProducts,
    productStock,
} from '../helpers/api';
import {
    assignModifierGroups,
    chooseSecondCatalogProduct,
    createModifier,
    createModifierGroup,
    deleteModifierSetup,
} from '../helpers/modifiers';
import { addProduct, openOrderFromTab, openPos, saveUnpaidDineIn } from '../helpers/pos';

test.use({ storageState: authState('admin') });

const viewports = [
    { width: 768, height: 1024 },
    { width: 1024, height: 768 },
] as const;

test('POS modifier selection persists and shows on kitchen tickets at tablet viewports', async ({ page }, testInfo) => {
    test.setTimeout(420000);

    const created = await createAreaAndTable(page, 'E2E mod');
    const products = await listProducts(page);
    const meal = products[0];
    expect(meal?.id).toBeGreaterThan(0);
    const ingredient = await chooseSecondCatalogProduct(products, meal);

    const suffix = `${Date.now().toString(36)}`;
    const groupId = await createModifierGroup(page, `E2E extras ${suffix}`, {
        selection_type: 'single',
        min_selections: 1,
        allow_quantity: '0',
    });
    const modifierName = `Extra cheese ${suffix}`;
    const modifierId = await createModifier(page, modifierName, groupId, {
        price: 1.5,
        product_id: ingredient.id,
    });
    await assignModifierGroups(page, meal.id, [groupId]);

    let orderId: number | null = null;
    let orderCode = '';

    try {
        const mealStockBefore = await productStock(page, meal.id);
        const ingredientStockBefore = await productStock(page, ingredient.id);

        for (const viewport of viewports) {
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await openPos(page);
            await addProduct(page, meal.name, { modifierName });
            await expect(page.locator('[data-zash-dine-modifier-meta]')).toContainText(modifierName);

            const shot = testInfo.outputPath(`modifiers-pos-${viewport.width}x${viewport.height}.png`);
            await page.screenshot({ path: shot, fullPage: true });
            await testInfo.attach(`modifiers-pos-${viewport.width}x${viewport.height}`, {
                path: shot,
                contentType: 'image/png',
            });

            if (viewport !== viewports[viewports.length - 1]) {
                await page.locator('#cart-button-reset').click().catch(() => undefined);
                const confirm = page.locator('#confirm-popup');
                if (await confirm.isVisible().catch(() => false)) {
                    await confirm.getByRole('button', { name: 'Yes' }).click();
                }
            }
        }

        await saveUnpaidDineIn(page, created.tableName);

        const mealStockAfter = await productStock(page, meal.id);
        const ingredientStockAfter = await productStock(page, ingredient.id);
        expect(mealStockAfter).toBeLessThan(mealStockBefore);
        expect(ingredientStockAfter).toBeLessThan(ingredientStockBefore);

        const orders = await apiSend(page, 'get', '/api/crud/ns.orders?per_page=10');
        const rows = (orders.data as Array<Record<string, unknown>> | undefined) ?? [];
        orderId = Number(rows[0]?.id ?? 0) || null;
        orderCode = String(rows[0]?.code ?? rows[0]?.title ?? '');
        expect(orderId).toBeGreaterThan(0);

        await openPos(page);
        await openOrderFromTab(page, 'Unpaid', orderCode || String(orderId));
        await expect(page.locator('[data-zash-dine-modifier-meta]')).toContainText(modifierName);

        // Tickets remain visible under All / Unassigned even without a kitchen assignment.
        for (const viewport of viewports) {
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await page.goto('/dashboard/zash-dine/kitchen');
            await expect(page.locator('body')).toContainText(meal.name, { timeout: 20000 });
            await expect(page.locator('body')).toContainText(modifierName, { timeout: 20000 });

            const kitchenShot = testInfo.outputPath(`modifiers-kitchen-${viewport.width}x${viewport.height}.png`);
            await page.screenshot({ path: kitchenShot, fullPage: true });
            await testInfo.attach(`modifiers-kitchen-${viewport.width}x${viewport.height}`, {
                path: kitchenShot,
                contentType: 'image/png',
            });
        }
    } finally {
        await assignModifierGroups(page, meal.id, []).catch(() => undefined);
        await deleteModifierSetup(page, groupId, [modifierId]);
        await deleteAreaAndTable(page, created.areaId, created.tableId, orderId);
    }
});
