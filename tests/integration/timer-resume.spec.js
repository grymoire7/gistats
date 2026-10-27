const { test, expect } = require('@playwright/test');

test('a running timer resumes after reload', async ({ page }) => {
  await page.goto('/');
  await page.evaluate(() => localStorage.setItem('gistats_timer_start', (Date.now() - 30000).toString()));

  await page.reload();

  await expect(page.locator('#timer-btn')).toHaveText('■');
  await expect(page.locator('[name="duration"]')).toHaveJSProperty('readOnly', true);
  expect(await page.evaluate(() => localStorage.getItem('gistats_timer_start'))).not.toBeNull();
});
