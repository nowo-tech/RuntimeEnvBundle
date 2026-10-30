import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { deleteVariables, gotoVariable, rowOf, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('RuntimeEnv screenshots', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  // Demo data comes from `make seed` (app:demo:seed); only e2e leftovers are removed.
  test.beforeEach(async ({ page }) => {
    await deleteVariables(page, /^E2E_/);
  });

  test('manage-panel — encrypted variables listed with masked values', async ({ page }) => {
    await page.goto('/_runtime_env');
    await expect(rowOf(page, 'MY_API_TOKEN')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'manage-panel.png') });
  });

  test('manage-edit — stored value never rendered back, delete behind a confirm dialog', async ({ page }) => {
    expect(await gotoVariable(page, 'PAYMENT_WEBHOOK_SECRET')).toBeTruthy();
    await rowOf(page, 'PAYMENT_WEBHOOK_SECRET').getByRole('link', { name: 'Edit' }).click();
    await expect(page.getByLabel('Value', { exact: true })).toHaveValue('');
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'manage-edit.png') });
  });

  test('runtime-env-bag — value read on the next request without restarting the worker', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText(/^Value:/)).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'runtime-env-bag.png') });
  });
});
