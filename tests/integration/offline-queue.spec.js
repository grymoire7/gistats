const { test, expect } = require('@playwright/test');
const { fillEntry } = require('./helpers');

test('a submit made while offline is queued in localStorage', async ({ page, context }) => {
  await page.goto('/');
  await page.evaluate(() => localStorage.removeItem('gistats_pending'));

  await context.setOffline(true);
  await fillEntry(page);
  await page.click('#entry-form button[type=submit]');

  await expect
    .poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('gistats_pending') || '[]').length))
    .toBe(1);
  await expect(page.locator('#pending-indicator')).toHaveText('1 queued');
});
