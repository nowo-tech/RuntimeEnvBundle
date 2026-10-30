import { expect, type Locator, type Page } from '@playwright/test';

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

/** Rows of the manage list (UiKit list scaffold, data-testid on the scaffold root). */
export function listRows(page: Page): Locator {
  return page.locator('[data-testid="runtime-env-variables"] table tbody tr');
}

/** The list row of a variable on the current page. */
export function rowOf(page: Page, name: string | RegExp): Locator {
  const exact = typeof name === 'string' ? new RegExp(`^${name}$`) : name;
  return listRows(page).filter({ has: page.locator('code', { hasText: exact }) });
}

/**
 * Open the list page (web_ui.list_page_size rows per page) that holds the variable; false when no page has it.
 */
export async function gotoVariable(page: Page, name: string | RegExp, maxPages = 10): Promise<boolean> {
  for (let n = 1; n <= maxPages; n++) {
    await page.goto(`/_runtime_env?page=${n}`);
    await expect(page.getByRole('heading', { name: 'Runtime environment variables' })).toBeVisible();
    if ((await rowOf(page, name).count()) > 0) {
      return true;
    }
    if (!(await page.locator('nav[aria-label="Pagination"] a[aria-label="Next"]').count())) {
      return false;
    }
  }
  return false;
}

/** Create a runtime variable through the manage panel unless it already exists. */
export async function ensureVariable(page: Page, name: string, value: string, description = ''): Promise<void> {
  if (await gotoVariable(page, name)) {
    return;
  }
  await page.goto('/_runtime_env/new');
  await page.getByLabel('Name', { exact: true }).fill(name);
  await page.getByLabel('Value', { exact: true }).fill(value);
  if (description !== '') {
    await page.getByLabel('Description', { exact: true }).fill(description);
  }
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Variable created.')).toBeVisible();
}

/** Delete one variable of the current list page through the UiKit confirm dialog. */
export async function deleteRow(page: Page, row: Locator): Promise<void> {
  await row.getByRole('button', { name: 'Delete' }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Delete' }).click();
  await expect(page.getByText('Variable deleted.')).toBeVisible();
}

/** Delete every variable whose name matches (leftovers from interrupted e2e runs). */
export async function deleteVariables(page: Page, pattern: RegExp): Promise<void> {
  while (await gotoVariable(page, pattern)) {
    await deleteRow(page, rowOf(page, pattern).first());
  }
}
