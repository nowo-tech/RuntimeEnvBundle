import { expect, type Page } from '@playwright/test';

/** Wait until the Symfony Web Debug Toolbar has finished loading (optional outside dev). */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.waitForSelector('.sf-toolbar', { state: 'visible', timeout: 20000 }).catch(() => undefined);
  const mini = page.locator('.sf-minitoolbar a').first();
  if (await mini.isVisible().catch(() => false)) {
    await mini.click();
  }
  await page
    .waitForFunction(
      () => {
        const bar = document.querySelector('.sf-toolbar') as HTMLElement | null;
        if (!bar || bar.offsetParent === null) {
          return true;
        }
        return !!document.querySelector('.sf-toolbar-block');
      },
      { timeout: 25000, polling: 250 },
    )
    .catch(() => undefined);
  await new Promise((r) => setTimeout(r, 400));
}

/** Create a runtime variable through the manage panel unless it already exists. */
export async function ensureVariable(page: Page, name: string, value: string, description = ''): Promise<void> {
  await page.goto('/_runtime_env');
  await expect(page.getByRole('heading', { name: 'Runtime environment variables' })).toBeVisible();
  if ((await page.locator('table.nowo-ui-table code', { hasText: new RegExp(`^${name}$`) }).count()) > 0) {
    return;
  }
  await page.getByRole('link', { name: 'New variable' }).click();
  await page.getByLabel('Name', { exact: true }).fill(name);
  await page.getByLabel('Value', { exact: true }).fill(value);
  if (description !== '') {
    await page.getByLabel('Description', { exact: true }).fill(description);
  }
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Variable created.')).toBeVisible();
}

/** Delete every variable whose name matches (leftovers from interrupted e2e runs). */
export async function deleteVariables(page: Page, pattern: RegExp): Promise<void> {
  await page.goto('/_runtime_env');
  page.on('dialog', (dialog) => dialog.accept());
  for (;;) {
    const row = page.locator('table.nowo-ui-table tbody tr', {
      has: page.locator('code', { hasText: pattern }),
    });
    if ((await row.count()) === 0) {
      return;
    }
    await row.first().getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText('Variable deleted.')).toBeVisible();
  }
}
