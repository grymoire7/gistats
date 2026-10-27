const { test, expect } = require('@playwright/test');

const USERNAME = process.env.GISTATS_USER || 'admin';
const PASSWORD = process.env.GISTATS_PASS || 'secret';

test('log in once and save the session', async ({ page }) => {
  await page.goto('/login');
  await page.fill('[name="username"]', USERNAME);
  await page.fill('[name="password"]', PASSWORD);
  await page.click('[type="submit"]');

  // The entry form only exists on the home page.
  await expect(page.locator('#entry-form-wrap')).toBeVisible();

  await page.context().storageState({ path: 'tmp/.auth/admin.json' });
});
