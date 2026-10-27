const { test, expect } = require('@playwright/test');
const { fillEntry } = require('./helpers');

test('submitting while the timer runs stops it', async ({ page }) => {
  await page.goto('/');
  await page.evaluate(() => localStorage.setItem('gistats_timer_start', (Date.now() - 90000).toString()));
  await page.reload();

  await fillEntry(page);
  await page.click('button.btn-primary');

  await expect
    .poll(() => page.evaluate(() => localStorage.getItem('gistats_timer_start')))
    .toBeNull();
});
