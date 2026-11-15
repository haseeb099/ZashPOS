/**
 * Real Chromium Modules upload + enable against a staging host.
 * Usage: node scripts/browser-modules-upload.mjs
 */
import fs from 'fs';
import path from 'path';
import { createRequire } from 'module';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const requireFromE2e = createRequire(path.join(root, '..', 'ZashPOS-phase5', 'e2e', 'package.json'));
let chromium;
try {
  ({ chromium } = await import('playwright'));
} catch {
  ({ chromium } = requireFromE2e('playwright'));
}
const staging = process.env.STAGING_ROOT || 'D:\\Nexo\\ZashPOS-staging-deploy';
const base = process.env.STAGING_URL || 'http://127.0.0.1:8016';
const zip = path.join(staging, 'storage', 'app', 'zashdine-packages', 'ZashDine-1.0.0.zip');
const evidencePath = path.join(root, 'storage', 'logs', 'phase5-browser-modules-upload.json');

if (!fs.existsSync(zip)) {
  console.error('ZIP missing:', zip);
  process.exit(1);
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
const evidence = {
  base,
  zip,
  steps: [],
  ok: false,
};

try {
  await page.goto(`${base}/sign-in`, { waitUntil: 'networkidle' });
  await page.getByLabel(/username/i).fill('admin');
  await page.getByLabel(/password/i).fill('password');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/dashboard/, { timeout: 30000 });
  evidence.steps.push('signed_in');

  await page.goto(`${base}/dashboard/modules/upload`, { waitUntil: 'networkidle' });
  evidence.steps.push('opened_upload');

  await page.locator('#upload-file').setInputFiles(zip);
  evidence.steps.push('selected_zip');

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle', timeout: 120000 }).catch(() => null),
    page.getByRole('button', { name: /^upload$/i }).click(),
  ]);
  evidence.steps.push({ uploaded: true, url: page.url(), bodySnippet: (await page.locator('body').innerText()).slice(0, 500) });

  await page.goto(`${base}/dashboard/modules`, { waitUntil: 'networkidle' });
  const listText = await page.locator('body').innerText();
  evidence.list_has_zashdine = /zash\s*dine/i.test(listText);
  evidence.steps.push('opened_list');

  // Prefer the Modules UI enable control (same CSRF/session as the browser).
  const disabledTab = page.getByText(/Disabled\s*\(/i).first();
  if (await disabledTab.count()) {
    await disabledTab.click().catch(() => null);
    await page.waitForTimeout(500);
  }

  const enableControl = page.getByRole('button', { name: /enable/i }).first();
  if (await enableControl.count()) {
    await enableControl.click();
    await page.waitForTimeout(2000);
    evidence.steps.push('clicked_enable_button');
  } else {
    // Fallback: XSRF cookie + Sanctum-style request from the page context.
    const enable = await page.evaluate(async () => {
      const xsrf = document.cookie.split(';').map(c => c.trim()).find(c => c.startsWith('XSRF-TOKEN='));
      const token = xsrf ? decodeURIComponent(xsrf.split('=').slice(1).join('=')) : (
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      );
      const res = await fetch('/api/modules/' + encodeURIComponent('ZashDine') + '/enable', {
        method: 'PUT',
        headers: {
          Accept: 'application/json',
          'X-XSRF-TOKEN': token,
          'X-CSRF-TOKEN': token,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      });
      const text = await res.text();
      let json = null;
      try { json = JSON.parse(text); } catch { /* ignore */ }
      return { status: res.status, text: text.slice(0, 500), json };
    });
    evidence.enable = enable;
    evidence.steps.push('enable_api_fallback');
  }

  await page.reload({ waitUntil: 'networkidle' });
  const afterText = await page.locator('body').innerText();
  evidence.enabled_visible = /Enabled\s*\([1-9]/i.test(afterText) || /ZashDine[\s\S]{0,80}enabled/i.test(afterText);
  evidence.after_snippet = afterText.slice(0, 600);

  evidence.module_dir_exists = fs.existsSync(path.join(staging, 'modules', 'ZashDine', 'config.xml'));
  evidence.ok = evidence.module_dir_exists && evidence.list_has_zashdine;

  fs.mkdirSync(path.dirname(evidencePath), { recursive: true });
  fs.writeFileSync(evidencePath, JSON.stringify(evidence, null, 2));
  console.log(JSON.stringify(evidence, null, 2));
  await browser.close();
  process.exit(evidence.ok ? 0 : 2);
} catch (error) {
  evidence.error = String(error);
  fs.mkdirSync(path.dirname(evidencePath), { recursive: true });
  fs.writeFileSync(evidencePath, JSON.stringify(evidence, null, 2));
  console.error(error);
  await browser.close();
  process.exit(1);
}
