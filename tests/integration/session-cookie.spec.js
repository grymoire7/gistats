const { test, expect } = require('@playwright/test');

const USERNAME = process.env.GISTATS_USER || 'admin';
const PASSWORD = process.env.GISTATS_PASS || 'secret';

test('login issues a 30-day http-only session cookie', async ({ browser }) => {
  // Explicit empty storage state: project-level use.storageState (the shared
  // admin login) otherwise becomes the default for manual contexts too.
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const page = await context.newPage();

  await page.goto('/login');
  await page.fill('[name="username"]', USERNAME);
  await page.fill('[name="password"]', PASSWORD);
  await page.click('[type="submit"]');
  await expect(page.locator('#entry-form-wrap')).toBeVisible();

  const session = (await context.cookies()).find((c) => c.name === 'gistats_sess');
  expect(session).toBeTruthy();
  expect(session.httpOnly).toBe(true);

  const thirtyDays = 30 * 24 * 60 * 60;
  const remaining = session.expires - Date.now() / 1000;
  expect(remaining).toBeGreaterThan(thirtyDays - 120);
  expect(remaining).toBeLessThan(thirtyDays + 120);
});
