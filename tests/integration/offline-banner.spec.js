const { test, expect } = require('@playwright/test');

test('offline banner shows and read controls disable when offline, hides when online', async ({ page, context }) => {
  await page.goto('/');
  await expect(page.locator('#entry-form-wrap')).toBeVisible();

  await context.setOffline(true);
  await expect(page.locator('#offline-banner')).toBeVisible();
  await expect(page.locator('[data-offline-disable]').first()).toHaveClass(/offline-disabled/);

  await context.setOffline(false);
  await expect(page.locator('#offline-banner')).toHaveCSS('display', 'none');
});
