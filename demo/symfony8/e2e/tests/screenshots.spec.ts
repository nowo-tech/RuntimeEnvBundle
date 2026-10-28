import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { deleteVariables, ensureVariable, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('RuntimeEnv screenshots', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  test.beforeEach(async ({ page }) => {
    await deleteVariables(page, /^E2E_/);
    await ensureVariable(page, 'DEMO_SAMPLE_VALUE', 'hello-from-runtime-env', 'Read by the demo home page');
    await ensureVariable(page, 'MY_API_TOKEN', 'tok_live_51Hx2demo', 'Third-party API token');
    await ensureVariable(page, 'FEATURE_CHECKOUT_V2', '1', 'Feature flag');
  });

  test('manage-panel — encrypted variables listed with masked values', async ({ page }) => {
    await page.goto('/_runtime_env');
    await expect(page.locator('table.nowo-ui-table code', { hasText: 'MY_API_TOKEN' })).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'manage-panel.png') });
  });

  test('runtime-env-bag — value read on the next request without restarting the worker', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText(/^Value:/)).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'runtime-env-bag.png') });
  });
});
