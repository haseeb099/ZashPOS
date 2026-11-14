import { apiSend, type CatalogProduct } from './api';
import type { Page } from '@playwright/test';

type Json = Record<string, unknown>;

function entryId(body: Json): number {
    const data = body.data as Json | undefined;
    const entry = data?.entry as Json | undefined;

    return Number(entry?.id);
}

export async function createModifierGroup(page: Page, name: string, options: {
    selection_type?: 'single' | 'multiple';
    min_selections?: number;
    max_selections?: number | '';
    allow_quantity?: '0' | '1';
} = {}): Promise<number> {
    const body = await apiSend(page, 'post', '/api/crud/zashdine.modifier-groups', {
        name,
        general: {
            selection_type: options.selection_type ?? 'single',
            min_selections: options.min_selections ?? 1,
            max_selections: options.max_selections ?? '',
            allow_quantity: options.allow_quantity ?? '0',
        },
    });

    return entryId(body);
}

export async function createModifier(page: Page, name: string, groupId: number, options: {
    price?: number;
    product_id?: number | '';
    status?: 'enabled' | 'disabled';
} = {}): Promise<number> {
    const body = await apiSend(page, 'post', '/api/crud/zashdine.modifiers', {
        name,
        general: {
            modifier_group_id: groupId,
            price: options.price ?? 1.25,
            product_id: options.product_id ?? '',
            status: options.status ?? 'enabled',
        },
    });

    return entryId(body);
}

export async function assignModifierGroups(page: Page, productId: number, groupIds: number[]): Promise<void> {
    await apiSend(page, 'put', `/api/zash-dine/products/${productId}/modifier-groups`, {
        group_ids: groupIds,
    });
}

export async function deleteModifierSetup(page: Page, groupId: number, modifierIds: number[]): Promise<void> {
    for (const id of modifierIds) {
        await apiSend(page, 'delete', `/api/crud/zashdine.modifiers/${id}`).catch(() => undefined);
    }

    await apiSend(page, 'delete', `/api/crud/zashdine.modifier-groups/${groupId}`).catch(() => undefined);
}

export async function chooseSecondCatalogProduct(products: CatalogProduct[], meal: CatalogProduct): Promise<CatalogProduct> {
    const other = products.find((row) => row.id !== meal.id);

    if (!other) {
        throw new Error('Need at least two catalog products for modifier ingredient stock.');
    }

    return other;
}
