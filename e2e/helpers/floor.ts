import { expect, type Page } from '@playwright/test';
import { apiSend } from './api';

export interface FloorTable {
    id: number;
    name: string;
    status: string;
    current_order_id: number | null;
    order: {
        id: number;
        code: string;
        total: number;
        payment_status: string;
    } | null;
    items: Array<{
        id: number;
        status: string;
        note: string | null;
        name: string | null;
        quantity: number;
    }>;
}

export function tableCard(page: Page, tableName: string) {
    return page.locator('article').filter({
        has: page.getByRole('heading', { name: tableName, exact: true }),
    });
}

export async function readFloorTable(page: Page, tableName: string): Promise<FloorTable | undefined> {
    const body = await apiSend(page, 'get', '/api/zash-dine/floor');
    const areas = ((body.data as { areas?: Array<{ tables?: FloorTable[] }> })?.areas) ?? [];

    for (const area of areas) {
        const table = area.tables?.find((candidate) => candidate.name === tableName);

        if (table) {
            return table;
        }
    }

    return undefined;
}

export async function expectTableStatus(page: Page, tableName: string, status: 'Occupied' | 'Available'): Promise<void> {
    await expect.poll(async () => tableCard(page, tableName).getByText(status, { exact: true }).count(), {
        timeout: 12000,
        intervals: [500, 1000, 1000, 2000],
    }).toBeGreaterThan(0);
}

export function armPrompt(page: Page, value: string): Promise<void> {
    return new Promise((resolve, reject) => {
        page.once('dialog', (dialog) => dialog.accept(value).then(resolve, reject));
    });
}

export function armConfirm(page: Page): Promise<void> {
    return new Promise((resolve, reject) => {
        page.once('dialog', (dialog) => dialog.accept().then(resolve, reject));
    });
}
