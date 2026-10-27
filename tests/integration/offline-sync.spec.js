const { test, expect } = require('@playwright/test');
const { uniqueOccurredAt } = require('./helpers');

test('a queued entry syncs when the browser comes back online', async ({ page, context }) => {
  await page.goto('/');
  await context.setOffline(true);

  await page.evaluate((occurredAt) => {
    localStorage.setItem(
      'gistats_pending',
      JSON.stringify([{ occurred_at: occurredAt, duration: '05:00', stool_type: '4', note: 'integration test' }])
    );
  }, uniqueOccurredAt());

  await context.setOffline(false);

  await expect
    .poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('gistats_pending') || '[]').length))
    .toBe(0);
  await expect(page.locator('#flash-msg')).toHaveText(/1 entry synced/);
});
