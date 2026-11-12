import { expect, test } from '@playwright/test';
import { authState } from '../helpers/auth';
import { createAreaAndTable, deleteAreaAndTable } from '../helpers/api';
import { tableCard } from '../helpers/floor';
import { openPos } from '../helpers/pos';

test.use({ storageState: authState('admin') });

const viewports = [
    { width: 768, height: 1024 },
    { width: 1024, height: 768 },
] as const;

test('Floor and POS render at tablet viewports', async ({ page }, testInfo) => {
    const created = await createAreaAndTable(page, 'E2E view');

    try {
        for (const viewport of viewports) {
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await page.goto('/dashboard/zash-dine/floor');
            await expect(page.getByRole('heading', { name: 'Floor' })).toBeVisible();
            await expect(tableCard(page, created.tableName)).toBeVisible();
            const floorShot = testInfo.outputPath(`floor-${viewport.width}x${viewport.height}.png`);
            await page.screenshot({ path: floorShot, fullPage: true });
            await testInfo.attach(`floor-${viewport.width}x${viewport.height}`, {
                path: floorShot,
                contentType: 'image/png',
            });

            await openPos(page);
            await expect(page.getByRole('button', { name: 'Order Type' })).toBeVisible();
            await expect(page.locator('#pay-button')).toBeVisible();
            const posShot = testInfo.outputPath(`pos-${viewport.width}x${viewport.height}.png`);
            await page.screenshot({ path: posShot, fullPage: true });
            await testInfo.attach(`pos-${viewport.width}x${viewport.height}`, {
                path: posShot,
                contentType: 'image/png',
            });
        }
    } finally {
        await deleteAreaAndTable(page, created.areaId, created.tableId);
    }
});
