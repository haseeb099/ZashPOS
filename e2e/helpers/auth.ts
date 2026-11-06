import { expect, type Page } from '@playwright/test';

export async function login(page: Page, username: string, password: string): Promise<void> {
    await page.goto('/sign-in');
    await expect(page.getByLabel('Username')).toBeVisible({ timeout: 30000 });
    await page.getByLabel('Username').fill(username);
    await page.getByLabel('Password').fill(password);
    const signedIn = page.waitForResponse((response) => response.url().includes('/auth/sign-in'));
    await page.getByRole('button', { name: 'Sign In' }).click();
    const response = await signedIn;

    if (!response.ok()) {
        throw new Error(`Sign-in failed (${response.status()}).`);
    }

    await expect(page).toHaveURL(/\/dashboard/, { timeout: 30000 });
}

export function authState(role: 'admin' | 'waiter' | 'cashier'): string {
    return `auth-state/${role}.json`;
}
