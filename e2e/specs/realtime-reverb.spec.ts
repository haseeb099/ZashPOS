import { expect, test, type Page } from '@playwright/test';
import { apiResult, apiSend, createAreaAndTable, deleteAreaAndTable, listProducts } from '../helpers/api';
import { authState } from '../helpers/auth';
import { baseURL } from '../load-env';

async function productDetails(page: Page, productId: number): Promise<{
    id: number;
    name: string;
    category_id: number;
    unit_quantity_id: number;
    unit_id: number;
    sale_price: number;
}> {
    const body = await apiSend(page, 'get', `/api/products/${productId}`);
    const product = (Array.isArray(body) ? body[0] : body) as Record<string, unknown>;
    const quantitiesBody = await apiSend(page, 'get', `/api/products/${productId}/units/quantities`);
    const quantities = (Array.isArray(quantitiesBody)
        ? quantitiesBody
        : ((quantitiesBody.data as Array<Record<string, unknown>> | undefined) ?? [])) as Array<Record<string, unknown>>;
    const unit = quantities[0] ?? {};

    return {
        id: Number(product.id ?? productId),
        name: String(product.name ?? ''),
        category_id: Number(product.category_id ?? 0),
        unit_quantity_id: Number(unit.id ?? 0),
        unit_id: Number(unit.unit_id ?? 0),
        sale_price: Number(unit.sale_price ?? unit.incl_tax_sale_price ?? 0),
    };
}

async function wipeKitchens(page: Page): Promise<void> {
    const existing = await apiSend(page, 'get', '/api/crud/zashdine.kitchens?per_page=50').catch(() => ({ data: [] }));
    const rows = (existing.data as Array<Record<string, unknown>> | undefined) ?? [];
    for (const row of rows) {
        const id = Number(row.id);
        if (id > 0) {
            await apiSend(page, 'put', `/api/crud/zashdine.kitchens/${id}`, {
                name: String(row.name ?? `Kitchen ${id}`),
                general: {
                    description: String(row.description ?? ''),
                    status: 'disabled',
                    category_ids: [],
                },
            }).catch(() => undefined);
            await apiSend(page, 'delete', `/api/crud/zashdine.kitchens/${id}`).catch(() => undefined);
        }
    }
}

async function createKitchen(page: Page, name: string, categoryIds: number[]): Promise<number> {
    const body = await apiSend(page, 'post', '/api/crud/zashdine.kitchens', {
        name,
        general: {
            description: 'Playwright reverb kitchen',
            status: 'enabled',
            category_ids: categoryIds,
        },
    });
    const data = body.data as Record<string, unknown> | undefined;
    const entry = data?.entry as Record<string, unknown> | undefined;

    return Number(entry?.id);
}

async function deleteKitchen(page: Page, kitchenId: number): Promise<void> {
    await apiSend(page, 'delete', `/api/crud/zashdine.kitchens/${kitchenId}`).catch(() => undefined);
}

async function defaultCustomerId(page: Page): Promise<number> {
    const body = await apiSend(page, 'get', '/api/customers');
    const rows = Array.isArray(body)
        ? body
        : ((body.data as Array<Record<string, unknown>> | undefined) ?? []);
    const first = rows[0] as Record<string, unknown> | undefined;
    const id = Number(first?.id ?? 0);
    expect(id, 'need a customer for unpaid dine-in').toBeGreaterThan(0);

    return id;
}

async function createUnpaidDineInOrder(
    page: Page,
    tableId: number,
    customerId: number,
    products: Array<{ id: number; name: string; unit_quantity_id: number; unit_id: number; sale_price: number }>,
): Promise<number> {
    const payload = {
        customer_id: customerId,
        type: { identifier: 'dine-in' },
        zash_dine_table_id: tableId,
        discount: 0,
        discount_type: 'percentage',
        discount_percentage: 0,
        coupons: [],
        payments: [],
        addresses: {
            shipping: {},
            billing: {},
        },
        subtotal: products.reduce((sum, product) => sum + Number(product.sale_price), 0),
        total: products.reduce((sum, product) => sum + Number(product.sale_price), 0),
        tendered: 0,
        note: 'Playwright reverb order',
        products: products.map((product) => ({
            product_id: product.id,
            name: product.name,
            quantity: 1,
            unit_price: product.sale_price,
            unit_quantity_id: product.unit_quantity_id,
            unit_id: product.unit_id,
            discount: 0,
            discount_type: 'percentage',
            discount_percentage: 0,
            tax_type: 'inclusive',
            tax_group_id: 1,
        })),
    };

    const body = await apiSend(page, 'post', '/api/orders', payload);
    const data = body.data as Record<string, unknown> | undefined;
    const order = (data?.order ?? body.order ?? data) as Record<string, unknown> | undefined;
    const orderId = Number(order?.id ?? 0);
    expect(orderId, `order create failed: ${JSON.stringify(body)}`).toBeGreaterThan(0);

    return orderId;
}

async function waitForLive(page: Page): Promise<void> {
    await expect(page.locator('[data-zash-dine-connection]')).toHaveText(/Live/i, { timeout: 45000 });
}

async function echoConnected(page: Page): Promise<boolean> {
    return page.evaluate(() => {
        const echo = (window as unknown as { Echo?: { connector?: { pusher?: { connection?: { state?: string } } } } }).Echo;

        return echo?.connector?.pusher?.connection?.state === 'connected';
    });
}

async function installWsProbe(page: Page, key: string): Promise<void> {
    await page.addInitScript((probeKey) => {
        const store = ((window as unknown as Record<string, unknown>)[probeKey] ??= {
            board: [] as unknown[],
            ready: [] as unknown[],
            ticketGets: 0,
            floorGets: 0,
        }) as {
            board: unknown[];
            ready: unknown[];
            ticketGets: number;
            floorGets: number;
        };

        (window as unknown as Record<string, unknown>).__zashProbe = store;

        const wrapEcho = (echo: {
            private: (name: string) => { listen: (event: string, cb: (payload: unknown) => void) => unknown };
            __zashWrapped?: boolean;
        }) => {
            if (!echo || echo.__zashWrapped) {
                return echo;
            }

            const original = echo.private.bind(echo);
            echo.private = ((name: string) => {
                const channel = original(name);
                const originalListen = channel.listen.bind(channel);
                channel.listen = ((event: string, cb: (payload: unknown) => void) => {
                    return originalListen(event, (payload: unknown) => {
                        if (String(event).includes('board-updated')) {
                            store.board.push(payload);
                        }
                        if (String(event).includes('meal-ready')) {
                            store.ready.push(payload);
                        }
                        cb(payload);
                    });
                }) as typeof channel.listen;

                return channel;
            }) as typeof echo.private;

            echo.__zashWrapped = true;

            return echo;
        };

        let echoValue: unknown;
        Object.defineProperty(window, 'Echo', {
            configurable: true,
            enumerable: true,
            get() {
                return echoValue;
            },
            set(value: unknown) {
                echoValue = wrapEcho(value as {
                    private: (name: string) => { listen: (event: string, cb: (payload: unknown) => void) => unknown };
                    __zashWrapped?: boolean;
                });
            },
        });
    }, key);
}

async function probeCounts(page: Page): Promise<{ board: number; ready: number }> {
    return page.evaluate(() => {
        const store = (window as unknown as { __zashProbe?: { board: unknown[]; ready: unknown[] } }).__zashProbe;

        return {
            board: store?.board.length ?? 0,
            ready: store?.ready.length ?? 0,
        };
    });
}

async function authChannel(page: Page, channelName: string): Promise<number> {
    const result = await apiResult(page, 'post', '/broadcasting/auth', {
        channel_name: channelName,
        socket_id: `${Date.now()}.${Math.floor(Math.random() * 10000)}`,
    });

    return result.status;
}

test.describe('ZashDine Reverb realtime', () => {
    test('chef/waiter receive board and ready alerts over WebSockets without relying on the 5s poll', async ({ browser }) => {
        test.setTimeout(420000);
        test.skip(process.env.E2E_REVERB !== '1', 'Set E2E_REVERB=1 with a live Reverb server for this browser proof.');

        const admin = await browser.newContext({ storageState: authState('admin'), baseURL: baseURL() });
        const chef = await browser.newContext({ storageState: authState('chef'), baseURL: baseURL() });
        const waiter = await browser.newContext({ storageState: authState('waiter'), baseURL: baseURL() });
        const adminPage = await admin.newPage();
        const chefPage = await chef.newPage();
        const waiterPage = await waiter.newPage();

        await installWsProbe(chefPage, '__zashChefProbe');
        await installWsProbe(waiterPage, '__zashWaiterProbe');

        const created = await createAreaAndTable(adminPage, 'E2E reverb');
        const products = await listProducts(adminPage);
        let kitchenId = 0;
        let orderId: number | null = null;

        const disableFiveSecondPolling = async (page: Page): Promise<void> => {
            await page.addInitScript(() => {
                const original = window.setInterval.bind(window);
                window.setInterval = ((handler: TimerHandler, timeout?: number, ...args: unknown[]) => {
                    if (timeout === 5000) {
                        return 0 as unknown as number;
                    }

                    return original(handler, timeout, ...args);
                }) as typeof window.setInterval;
            });
        };

        try {
            const detailed = [];
            for (const product of products.slice(0, 20)) {
                detailed.push(await productDetails(adminPage, product.id));
            }
            const product = detailed.find((row) => row.category_id > 0 && row.unit_quantity_id > 0);
            expect(product, 'need a product with a category').toBeTruthy();

            await wipeKitchens(adminPage);
            kitchenId = await createKitchen(adminPage, `Reverb Grill ${Date.now().toString(36)}`, [product!.category_id]);
            expect(kitchenId).toBeGreaterThan(0);

            const customerId = await defaultCustomerId(waiterPage);
            orderId = await createUnpaidDineInOrder(waiterPage, created.tableId, customerId, [product!]);

            await disableFiveSecondPolling(chefPage);
            await disableFiveSecondPolling(waiterPage);

            await chefPage.goto('/dashboard/zash-dine/kitchen');
            await waiterPage.goto('/dashboard/zash-dine/floor');
            await expect(chefPage.locator('[data-zash-dine-kitchen-screen]')).toBeVisible({ timeout: 30000 });
            await expect(waiterPage.locator('[data-zash-dine-realtime="floor"]')).toBeVisible({ timeout: 30000 });

            await waitForLive(chefPage);
            await waitForLive(waiterPage);
            await expect.poll(async () => echoConnected(chefPage), { timeout: 30000 }).toBeTruthy();
            await expect.poll(async () => echoConnected(waiterPage), { timeout: 30000 }).toBeTruthy();

            expect(await authChannel(chefPage, 'private-zash-dine.kitchen')).toBe(200);
            expect(await authChannel(waiterPage, 'private-zash-dine.floor')).toBe(200);

            await chefPage.locator('#zash-dine-kitchen-filter').selectOption(String(kitchenId));
            await expect.poll(async () => {
                return chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenId}"]`).count();
            }, { timeout: 20000 }).toBeGreaterThan(0);

            const ticket = chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenId}"]`).first();
            const ticketId = await ticket.getAttribute('data-zash-dine-ticket-id');
            expect(ticketId).toBeTruthy();

            const chefBoardBefore = (await probeCounts(chefPage)).board;
            const waiterBoardBefore = (await probeCounts(waiterPage)).board;
            const waiterReadyBefore = (await probeCounts(waiterPage)).ready;

            const preparingStarted = Date.now();
            await ticket.getByRole('button', { name: 'Start preparing', exact: true }).click();
            await expect.poll(async () => ticket.getAttribute('data-zash-dine-ticket-status'), { timeout: 10000 })
                .toBe('preparing');
            await expect.poll(async () => (await probeCounts(chefPage)).board, { timeout: 10000 })
                .toBeGreaterThan(chefBoardBefore);
            await expect.poll(async () => (await probeCounts(waiterPage)).board, { timeout: 10000 })
                .toBeGreaterThan(waiterBoardBefore);
            expect(Date.now() - preparingStarted).toBeLessThan(4500);

            const readyStarted = Date.now();
            await ticket.getByRole('button', { name: 'Mark ready', exact: true }).click();
            await expect.poll(async () => ticket.getAttribute('data-zash-dine-ticket-status'), { timeout: 10000 })
                .toBe('ready');
            await expect.poll(async () => (await probeCounts(waiterPage)).ready, { timeout: 10000 })
                .toBeGreaterThan(waiterReadyBefore);
            expect(Date.now() - readyStarted).toBeLessThan(4500);

            await expect(waiterPage.locator('body')).toContainText(/ready/i, { timeout: 10000 });

            // Unauthorized channel subscriptions
            expect(await authChannel(waiterPage, 'private-zash-dine.kitchen')).toBe(403);
            expect(await authChannel(chefPage, 'private-zash-dine.floor')).toBe(403);
            const waiterId = await waiterPage.evaluate(() => {
                const ns = (window as unknown as { ns?: { user?: { id?: number | string } } }).ns;

                return Number(ns?.user?.id ?? 0);
            });
            const foreignId = waiterId > 0 ? waiterId + 999999 : 999999;
            expect(await authChannel(waiterPage, `private-App.User.${foreignId}`)).toBe(403);
            if (waiterId > 0) {
                expect(await authChannel(waiterPage, `private-App.User.${waiterId}`)).toBe(200);
            }

            // Duplicate event_id: after prior ticket traffic settles, two identical
            // payloads must trigger exactly one refresh.
            await chefPage.waitForLoadState('networkidle');
            let ticketGets = 0;
            let pendingTicketGets = 0;
            await chefPage.route('**/api/zash-dine/kitchen/tickets**', async (route) => {
                ticketGets += 1;
                pendingTicketGets += 1;
                try {
                    await route.continue();
                } finally {
                    pendingTicketGets -= 1;
                }
            });

            await expect.poll(() => pendingTicketGets, { timeout: 10000 }).toBe(0);
            ticketGets = 0;

            const dupId = `e2e-dup-${Date.now()}`;
            const emitted = await chefPage.evaluate((eventId) => {
                const echo = (window as unknown as {
                    Echo?: {
                        connector?: {
                            pusher?: {
                                channel: (name: string) => { emit?: (event: string, data: unknown) => void } | undefined;
                            };
                        };
                    };
                }).Echo;
                const channel = echo?.connector?.pusher?.channel('private-zash-dine.kitchen');
                if (!channel || typeof channel.emit !== 'function') {
                    return false;
                }

                channel.emit('zash-dine.board-updated', { event_id: eventId });
                channel.emit('zash-dine.board-updated', { event_id: eventId });

                return true;
            }, dupId);
            expect(emitted).toBeTruthy();
            await expect.poll(() => ticketGets, { timeout: 10000 }).toBe(1);
            await expect.poll(() => pendingTicketGets, { timeout: 10000 }).toBe(0);
            await chefPage.waitForTimeout(1000);
            expect(ticketGets).toBe(1);

            // Reconnect refresh
            ticketGets = 0;
            await chefPage.evaluate(() => {
                const echo = (window as unknown as { Echo?: { connector?: { pusher?: { connection: { disconnect: () => void; connect: () => void } } } } }).Echo;
                echo?.connector?.pusher?.connection.disconnect();
            });
            await expect(chefPage.locator('[data-zash-dine-connection]')).not.toHaveText(/Live/i, { timeout: 15000 });
            await chefPage.evaluate(() => {
                const echo = (window as unknown as { Echo?: { connector?: { pusher?: { connect: () => void; connection: { connect: () => void } } } } }).Echo;
                echo?.connector?.pusher?.connect?.();
                echo?.connector?.pusher?.connection?.connect?.();
            });
            await waitForLive(chefPage);
            await expect.poll(() => ticketGets, { timeout: 20000 }).toBeGreaterThan(0);
        } finally {
            await chefPage.unroute('**/api/zash-dine/kitchen/tickets**').catch(() => undefined);
            await deleteKitchen(adminPage, kitchenId);
            await deleteAreaAndTable(adminPage, created.areaId, created.tableId, orderId);
            await waiter.close();
            await chef.close();
            await admin.close();
        }
    });

    // Browser-only polling fallback (Echo forced unavailable in-page). Server-side
    // Reverb outage + NotificationCreatedEvent coverage lives in ZashDineRealtimeTest.
    test('kitchen status still succeeds and boards keep polling when Reverb is unavailable', async ({ browser }) => {
        test.setTimeout(300000);

        const admin = await browser.newContext({ storageState: authState('admin'), baseURL: baseURL() });
        const chef = await browser.newContext({ storageState: authState('chef'), baseURL: baseURL() });
        const adminPage = await admin.newPage();
        const chefPage = await chef.newPage();
        const created = await createAreaAndTable(adminPage, 'E2E pollfallback');
        const products = await listProducts(adminPage);
        let kitchenId = 0;
        let orderId: number | null = null;

        try {
            const detailed = [];
            for (const product of products.slice(0, 20)) {
                detailed.push(await productDetails(adminPage, product.id));
            }
            const product = detailed.find((row) => row.category_id > 0 && row.unit_quantity_id > 0);
            expect(product, 'need a product with a category').toBeTruthy();

            await wipeKitchens(adminPage);
            kitchenId = await createKitchen(adminPage, `Poll Grill ${Date.now().toString(36)}`, [product!.category_id]);
            const customerId = await defaultCustomerId(adminPage);
            orderId = await createUnpaidDineInOrder(adminPage, created.tableId, customerId, [product!]);

            // Force Echo/Reverb failure in this tab without affecting other suites' servers.
            await chefPage.addInitScript(() => {
                Object.defineProperty(window, 'Echo', {
                    configurable: true,
                    get() {
                        throw new Error('simulated Echo unavailable');
                    },
                    set() {
                        // ignore dashboard assignment
                    },
                });
            });

            await chefPage.goto('/dashboard/zash-dine/kitchen');
            await expect(chefPage.locator('[data-zash-dine-kitchen-screen]')).toBeVisible({ timeout: 30000 });
            await expect(chefPage.locator('[data-zash-dine-connection]')).toHaveText(/Polling/i, { timeout: 15000 });

            await chefPage.locator('#zash-dine-kitchen-filter').selectOption(String(kitchenId));
            await expect.poll(async () => {
                return chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenId}"]`).count();
            }, { timeout: 20000 }).toBeGreaterThan(0);

            const ticket = chefPage.locator(`[data-zash-dine-ticket-kitchen="${kitchenId}"]`).first();
            const ticketId = Number(await ticket.getAttribute('data-zash-dine-ticket-id'));
            expect(ticketId).toBeGreaterThan(0);

            const preparing = await apiResult(chefPage, 'post', `/api/zash-dine/order-items/${ticketId}/kitchen-status`, {
                status: 'preparing',
            });
            expect(preparing.status).toBe(200);

            await expect.poll(async () => ticket.getAttribute('data-zash-dine-ticket-status'), { timeout: 15000 })
                .toBe('preparing');

            const ready = await apiResult(chefPage, 'post', `/api/zash-dine/order-items/${ticketId}/kitchen-status`, {
                status: 'ready',
            });
            expect(ready.status).toBe(200);
            await expect.poll(async () => ticket.getAttribute('data-zash-dine-ticket-status'), { timeout: 15000 })
                .toBe('ready');
        } finally {
            await deleteKitchen(adminPage, kitchenId);
            await deleteAreaAndTable(adminPage, created.areaId, created.tableId, orderId);
            await chef.close();
            await admin.close();
        }
    });
});
