import { test, expect } from '@playwright/test';
import { deleteRow, ensureVariable, gotoVariable, listRows, rowOf } from './helpers';

/** REQ-DEMO-013 — RuntimeEnv demo happy path (manage panel CRUD → RuntimeEnvBag read on the next request). */
test.describe('RuntimeEnv demo', () => {
  test('home page explains the demo and links to the panel', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.getByRole('heading', { name: /Runtime Env Bundle/ })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open /_runtime_env' })).toBeVisible();
  });

  test('manage list is paginated and masks every value (REQ-PERF-001, UiKit list scaffold)', async ({ page }) => {
    await page.goto('/_runtime_env');
    await expect(listRows(page)).toHaveCount(10);
    await expect(page.locator('[data-testid="runtime-env-masked-value"]').first()).toHaveText('••••••••');
    await expect(page.locator('[data-testid="runtime-env-variables"]')).not.toContainText('demo-placeholder-token');
    await page.locator('nav[aria-label="Pagination"] a[aria-label="Next"]').first().click();
    await expect(page).toHaveURL(/page=2/);
    expect(await listRows(page).count()).toBeGreaterThan(0);
  });

  test('create, mask, edit and delete a variable in the manage panel', async ({ page }) => {
    const name = `E2E_${Date.now()}`;
    await ensureVariable(page, name, 'e2e-placeholder-value', 'Created by Playwright');
    expect(await gotoVariable(page, name)).toBeTruthy();
    const row = rowOf(page, name);
    await expect(row).toContainText('••••••••');
    await expect(page.locator('[data-testid="runtime-env-variables"]')).not.toContainText('e2e-placeholder-value');

    await row.getByRole('link', { name: 'Edit' }).click();
    await expect(page.getByLabel('Name', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Value', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('Value', { exact: true })).toHaveCSS('-webkit-text-security', 'disc');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Variable updated.')).toBeVisible();

    // Delete goes through the UiKit confirm <dialog> (no window.confirm): cancelling keeps the row.
    expect(await gotoVariable(page, name)).toBeTruthy();
    let dialogs = 0;
    page.on('dialog', () => dialogs++);
    await rowOf(page, name).getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(rowOf(page, name)).toHaveCount(1);

    await deleteRow(page, rowOf(page, name));
    expect(dialogs).toBe(0);
    expect(await gotoVariable(page, name)).toBeFalsy();
  });

  test('a delete without a valid CSRF token is refused', async ({ page, request }) => {
    expect(await gotoVariable(page, 'MY_API_TOKEN')).toBeTruthy();
    const edit = await rowOf(page, 'MY_API_TOKEN').getByRole('link', { name: 'Edit' }).getAttribute('href');
    const id = (edit ?? '').match(/\/(\d+)\/edit$/)?.[1];
    // The CSRF form of the row's delete action lives in its confirm <dialog>.
    const action = await page.locator(`dialog form[action$="/${id}/delete"]`).getAttribute('action');
    expect(action).toMatch(/\/_runtime_env\/\d+\/delete$/);
    const response = await request.post(action as string, { form: { 'runtime_env_delete[_token]': 'forged' } });
    expect(response.status()).toBe(403);
  });

  test('RuntimeEnvBag exposes the stored value on the next request', async ({ page }) => {
    await ensureVariable(page, 'DEMO_SAMPLE_VALUE', 'hello-from-runtime-env', 'Read by the demo home page');
    await page.goto('/');
    const value = page.locator('p', { hasText: /^Value:/ }).locator('code');
    await expect(value).toBeVisible();
    await expect(value).not.toHaveText('');
  });
});
