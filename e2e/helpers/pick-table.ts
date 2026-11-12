import { expect, type Page } from '@playwright/test';

/**
 * Selects a dine-in table through the ZashDine Vue picker popup.
 */
export async function pickTable(page: Page, tableName: string, _fallbackId?: number): Promise<string> {
    const popup = page.locator('#zash-dine-table-picker');
    await expect(popup).toBeVisible({ timeout: 20000 });
    await expect(popup.getByRole('button', { name: new RegExp(tableName) })).toBeVisible({ timeout: 15000 });
    await popup.getByRole('button', { name: new RegExp(tableName) }).click();
    await popup.getByRole('button', { name: 'Use this table', exact: true }).click();
    await expect(popup).toBeHidden({ timeout: 15000 });

    return tableName;
}

export function tablePicker(page: Page) {
    return page.locator('#zash-dine-table-picker');
}

export async function expectNoAvailableTablesPopup(page: Page): Promise<void> {
    const popup = tablePicker(page);
    await expect(popup).toBeVisible({ timeout: 20000 });
    await expect(popup.getByText(/No available tables/i)).toBeVisible();
    await expect(popup.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
}

export async function cancelTablePicker(page: Page): Promise<void> {
    const popup = tablePicker(page);
    await expect(popup).toBeVisible({ timeout: 20000 });
    await popup.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(popup).toBeHidden({ timeout: 15000 });
}

export async function dismissTablePickerWithEscape(page: Page): Promise<void> {
    const popup = tablePicker(page);
    await expect(popup).toBeVisible({ timeout: 20000 });
    await page.keyboard.press('Escape');
    await expect(popup).toBeHidden({ timeout: 15000 });
}

export async function dismissTablePickerWithX(page: Page): Promise<void> {
    const popup = tablePicker(page);
    await expect(popup).toBeVisible({ timeout: 20000 });
    await popup.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(popup).toBeHidden({ timeout: 15000 });
}
