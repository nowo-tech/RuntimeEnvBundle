import { test, expect } from '@playwright/test';
import { ensureVariable } from './helpers';

/** REQ-DEMO-013 — RuntimeEnv demo happy path (manage panel CRUD → RuntimeEnvBag read on the next request). */
test.describe('RuntimeEnv demo', () => {
  test('home page explains the demo and links to the panel', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.getByRole('heading', { name: /Runtime Env Bundle/ })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open /_runtime_env' })).toBeVisible();
  });

  test('create, mask, edit and delete a variable in the manage panel', async ({ page }) => {
    const name = `E2E_${Date.now()}`;
    await ensureVariable(page, name, 'super-secret-value', 'Created by Playwright');
    const row = page.locator('table.nowo-ui-table tbody tr', { has: page.locator('code', { hasText: name }) });
    await expect(row).toBeVisible();
    await expect(row).toContainText('••••••••');
    await expect(page.locator('table.nowo-ui-table')).not.toContainText('super-secret-value');

    await row.getByRole('link', { name: 'Edit' }).click();
    await expect(page.getByLabel('Name', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Value', { exact: true })).toHaveValue('');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Variable updated.')).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await page
      .locator('table.nowo-ui-table tbody tr', { has: page.locator('code', { hasText: name }) })
      .getByRole('button', { name: 'Delete' })
      .click();
    await expect(page.getByText('Variable deleted.')).toBeVisible();
    await expect(page.locator('table.nowo-ui-table code', { hasText: name })).toHaveCount(0);
  });

  test('RuntimeEnvBag exposes the stored value on the next request', async ({ page }) => {
    await ensureVariable(page, 'DEMO_SAMPLE_VALUE', 'hello-from-runtime-env', 'Read by the demo home page');
    await page.goto('/');
    const value = page.locator('p', { hasText: /^Value:/ }).locator('code');
    await expect(value).toBeVisible();
    await expect(value).not.toHaveText('');
  });
});
