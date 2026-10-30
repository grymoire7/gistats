const { test, expect } = require('@playwright/test');
const { uniqueOccurredAt } = require('./helpers');

test('sync explains itself when the session has expired instead of retrying forever', async ({ browser }) => {
  // Explicit empty storage state so the shared admin login doesn't leak in.
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const page = await context.newPage();
  await page.goto('/login');

  await page.evaluate((occurredAt) => {
    localStorage.setItem(
      'gistats_pending',
      JSON.stringify([{ occurred_at: occurredAt, duration: '05:00', stool_type: '4', note: '', urgency: '0' }])
    );
  }, uniqueOccurredAt());

  await page.evaluate(() => window.syncQueue());

  const flash = page.locator('#flash-msg');
  await expect(flash).toContainText('Session expired');
  await expect(flash.locator('a')).toHaveAttribute('href', '/login');

  // The queue must survive so the entries can sync after re-login.
  await expect
    .poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('gistats_pending') || '[]').length))
    .toBe(1);
});
