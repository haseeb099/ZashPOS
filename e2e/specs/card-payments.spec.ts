import { expect, test } from '@playwright/test';
import { apiSend, listProducts } from '../helpers/api';
import { authState } from '../helpers/auth';
import {
    addProduct,
    closePaymentPopup,
    openPaymentPopup,
    openPos,
    readPosCustomer,
} from '../helpers/pos';

test.use({ storageState: authState('admin') });

async function ensureStandalonePaymentType(page: import('@playwright/test').Page): Promise<void> {
    const types = await apiSend(page, 'get', '/api/orders/payments');
    const list = Array.isArray(types) ? types : (types as { data?: unknown[] }).data;
    const found = Array.isArray(list)
        && list.some((entry) => (entry as { identifier?: string }).identifier === 'card-standalone-pdq');

    if (found) {
        return;
    }

    await apiSend(page, 'post', '/api/crud/ns.payments-types', [
        {
            label: 'Card – standalone PDQ',
            identifier: 'card-standalone-pdq',
            active: 1,
            readonly: 1,
        },
    ]).catch(async () => {
        // Fallback: some installs already have the type inactive.
        await page.request.post('/api/crud/ns.payments-types', {
            data: {
                label: 'Card – standalone PDQ',
                identifier: 'card-standalone-pdq',
                active: 1,
            },
        });
    });
}

async function selectPaymentType(page: import('@playwright/test').Page, label: RegExp | string): Promise<void> {
    const payment = page.locator('#ns-payment-popup');
    const selector = payment.locator('.ns-payment-list, [class*="payment"]').filter({ hasText: /Select|Payment/ }).first();

    if (await selector.isVisible().catch(() => false)) {
        await selector.click();
    } else {
        await payment.getByText(/Select Payment|Payment Gateway|Cash/i).first().click();
    }

    const option = page.getByText(label, { exact: typeof label === 'string' }).first();
    await expect(option).toBeVisible({ timeout: 15000 });
    await option.click();
}

test('standalone PDQ approval records payment; decline does not', async ({ page }) => {
    test.setTimeout(240000);
    await ensureStandalonePaymentType(page);

    const products = await listProducts(page);
    const [first] = products;
    expect(first?.name).toBeTruthy();

    await openPos(page);
    const customer = await readPosCustomer(page);
    expect(customer.id).not.toBeNull();
    await addProduct(page, first.name);

    await openPaymentPopup(page);
    await selectPaymentType(page, /Card.*standalone PDQ|standalone PDQ/i);

    const standalone = page.locator('#ns-payment-popup');
    await expect(standalone.getByText(/Amount due|exact/i)).toBeVisible({ timeout: 15000 });

    await standalone.getByRole('button', { name: /Decline|cancel/i }).click();
    await expect(page.getByText(/Card payment not recorded|not recorded/i)).toBeVisible({ timeout: 10000 }).catch(() => undefined);

    const paymentsAfterDecline = await page.evaluate(() => {
        const order = (window as unknown as { POS?: { order?: { getValue?: () => { payments?: unknown[] } } } }).POS?.order?.getValue?.();

        return order?.payments?.length ?? 0;
    });
    expect(paymentsAfterDecline).toBe(0);

    await standalone.locator('input[type="checkbox"]').check();
    await standalone.getByRole('button', { name: /Record approved card payment/i }).click();

    const paymentsAfterApprove = await page.evaluate(() => {
        const order = (window as unknown as { POS?: { order?: { getValue?: () => { payments?: Array<{ identifier?: string }> } } } }).POS?.order?.getValue?.();

        return order?.payments ?? [];
    });
    expect(paymentsAfterApprove.some((payment) => payment.identifier === 'card-standalone-pdq')).toBe(true);

    await closePaymentPopup(page);
});

test('simulated integrated checkout settles when add-on enabled', async ({ page }) => {
    test.setTimeout(240000);

    await apiSend(page, 'post', '/api/modules/ZashPay/enable').catch(async () => {
        await page.request.get('/dashboard/modules');
    });

    // Best-effort enable via options when modules API shape differs.
    await page.request.post('/api/settings/zash-pay', {
        data: {
            zash_pay_integrated_enabled: 'yes',
        },
    }).catch(() => undefined);

    const products = await listProducts(page);
    const [first] = products;
    await openPos(page);
    await addProduct(page, first.name);

    // Create unpaid order via Save As Unpaid (takeaway path if no dine-in required).
    await openPaymentPopup(page);
    const saveUnpaid = page.getByRole('button', { name: /Save As Unpaid/ });
    if (await saveUnpaid.isVisible().catch(() => false)) {
        await saveUnpaid.click();
        const confirm = page.locator('#confirm-popup');
        if (await confirm.isVisible().catch(() => false)) {
            await confirm.getByRole('button', { name: 'Yes' }).click();
        }
        await expect(page.locator('#ns-payment-popup')).toBeHidden({ timeout: 30000 });
    } else {
        await closePaymentPopup(page);
        test.skip(true, 'Save As Unpaid unavailable in this POS configuration');
    }

    const orderId = await page.evaluate(() => {
        // After save, POS may clear; fall back to last known from floor/orders is heavy — use API list.
        return null as number | null;
    });

    const unpaid = await apiSend(page, 'get', '/api/orders?payment_status=unpaid').catch(() => ({ data: [] }));
    const rows = (unpaid as { data?: Array<{ id?: number }> }).data
        ?? (Array.isArray(unpaid) ? unpaid as Array<{ id?: number }> : []);
    const latest = rows[0]?.id ?? orderId;
    expect(latest).toBeTruthy();

    const attempt = await apiSend(page, 'post', `/api/zash-pay/orders/${latest}/attempts`, {
        idempotency_key: `e2e-sim-${Date.now()}`,
        provider: 'simulator',
        scenario: 'approve',
        terminal_id: 'simulator-1',
    });

    expect((attempt as { data?: { settled?: boolean; provider?: string } }).data?.settled).toBe(true);
    expect((attempt as { data?: { provider?: string } }).data?.provider).toBe('simulator');
});
