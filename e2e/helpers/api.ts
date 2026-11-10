import { expect, type Page } from '@playwright/test';
import { baseURL } from '../load-env';

type Json = Record<string, unknown>;

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
    const readToken = async (): Promise<string> => {
        const cookies = await page.context().cookies();
        const token = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');

        return decodeURIComponent(token?.value ?? '');
    };

    let token = await readToken();

    if (token === '') {
        await page.request.get('/sanctum/csrf-cookie');
        token = await readToken();
    }

    const origin = new URL(baseURL()).origin;

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Origin: origin,
        Referer: `${origin}/`,
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': token,
    };
}

export async function apiResult(
    page: Page,
    method: 'get' | 'post' | 'put' | 'delete',
    url: string,
    data?: Json,
): Promise<{ status: number; body: Json }> {
    const headers = await csrfHeaders(page);
    const response = await page.request.fetch(url, { method, headers, data });
    const body = await response.json().catch(() => ({}));

    return { status: response.status(), body: body as Json };
}

export async function apiSend(
    page: Page,
    method: 'get' | 'post' | 'put' | 'delete',
    url: string,
    data?: Json,
): Promise<Json> {
    const headers = await csrfHeaders(page);
    const response = await page.request.fetch(url, { method, headers, data });
    const body = await response.json().catch(() => ({}));

    if (!response.ok()) {
        throw new Error(`${method.toUpperCase()} ${url} failed (${response.status()}): ${JSON.stringify(body)}`);
    }

    return body as Json;
}

export async function createAreaAndTable(page: Page, prefix: string): Promise<{
    areaId: number;
    areaName: string;
    tableId: number;
    tableName: string;
}> {
    const suffix = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`;
    const areaName = `${prefix} area ${suffix}`;
    const tableName = `${prefix} table ${suffix}`;

    const area = await apiSend(page, 'post', '/api/crud/zashdine.areas', {
        name: areaName,
        general: {
            description: 'Playwright area',
            status: 'enabled',
        },
    });
    const areaId = entryId(area);

    const table = await apiSend(page, 'post', '/api/crud/zashdine.tables', {
        name: tableName,
        general: {
            area_id: areaId,
            seats: 4,
        },
    });
    const tableId = entryId(table);

    return { areaId, areaName, tableId, tableName };
}

export async function deleteAreaAndTable(page: Page, areaId: number, tableId: number, orderId?: number | null): Promise<void> {
    if (orderId) {
        await apiSend(page, 'delete', `/api/crud/ns.orders/${orderId}`).catch(() => undefined);
    }

    await apiSend(page, 'delete', `/api/crud/zashdine.tables/${tableId}`).catch(() => undefined);
    await apiSend(page, 'delete', `/api/crud/zashdine.areas/${areaId}`).catch(() => undefined);
}

export interface CatalogProduct {
    id: number;
    name: string;
}

export async function listProducts(page: Page): Promise<CatalogProduct[]> {
    const body = await apiSend(page, 'get', '/api/crud/ns.products?per_page=50');
    const rows = (body.data as Json[] | undefined) ?? [];

    return rows
        .map((row) => ({
            id: Number(row.id),
            name: String(row.name ?? ''),
        }))
        .filter((row) => row.id > 0 && row.name !== '');
}

export async function orderCount(page: Page): Promise<number> {
    const body = await apiSend(page, 'get', '/api/crud/ns.orders?per_page=1');

    return Number(body.total);
}

function entryId(body: Json): number {
    const data = body.data as Json | undefined;
    const entry = data?.entry as Json | undefined;

    return Number(entry?.id);
}

export async function productStock(page: Page, productId: number): Promise<number> {
    const body = await apiSend(page, 'get', `/api/products/${productId}/units/quantities`);
    const quantities = (Array.isArray(body) ? body : []) as Array<{ quantity?: number }>;

    if (quantities.length === 0) {
        throw new Error(`No unit quantities for product ${productId}: ${JSON.stringify(body).slice(0, 500)}`);
    }

    return quantities.reduce((sum, row) => sum + Number(row.quantity ?? 0), 0);
}

export async function expectSnack(page: Page, pattern: RegExp | string): Promise<void> {
    await expect(page.locator('#snack-wrapper')).toContainText(pattern, { timeout: 20000 });
}

export async function setCustomerGroupMinimalCreditPayment(
    page: Page,
    groupId: number,
    minimalCreditPayment: number,
): Promise<void> {
    const current = await apiSend(page, 'get', `/api/customers-groups/${groupId}`);
    const group = (Array.isArray(current) ? current[0] : current) as Json;

    await apiSend(page, 'put', `/api/crud/ns.customers-groups/${groupId}`, {
        name: String(group.name ?? 'Walk-in'),
        general: {
            reward_system_id: group.reward_system_id ?? null,
            minimal_credit_payment: minimalCreditPayment,
            description: String(group.description ?? 'Playwright group payment rule'),
        },
    });
}
