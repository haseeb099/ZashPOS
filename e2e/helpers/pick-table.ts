import type { Dialog, Page } from '@playwright/test';

/**
 * Accepts the dine-in table window.prompt.
 * Phase 2c should only need to change this helper when prompts become popups.
 *
 * Return dialog.accept() from the listener so Playwright can finish the click
 * that opened the prompt.
 */
export function pickTable(page: Page, tableName: string, fallbackId?: number): Promise<string> {
    return new Promise((resolve, reject) => {
        page.once('dialog', (dialog: Dialog) => {
            if (dialog.type() !== 'prompt' || !dialog.message().includes('Select a table')) {
                reject(new Error(`Expected the dine-in table prompt, received: ${dialog.message()}`));

                return dialog.dismiss();
            }

            const line = dialog.message().split('\n').find((row) => row.includes(tableName));
            const tableId = line?.split(':')[0]?.trim() || (fallbackId ? String(fallbackId) : '');

            if (tableId === '') {
                reject(new Error(`Table "${tableName}" was not offered.\n${dialog.message()}`));

                return dialog.dismiss();
            }

            return dialog.accept(tableId).then(() => {
                resolve(tableId);
            });
        });
    });
}
