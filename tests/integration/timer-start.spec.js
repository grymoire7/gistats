const { test, expect } = require('@playwright/test');

test('tapping the timer button starts the timer', async ({ page }) => {
  await page.goto('/');
  // Fresh context: no timer state leaked from an earlier test.
  expect(await page.evaluate(() => localStorage.getItem('gistats_timer_start'))).toBeNull();

  await page.click('#timer-btn');

  await expect(page.locator('#timer-btn')).toHaveText('■');
  await expect(page.locator('[name="duration"]')).toHaveJSProperty('readOnly', true);
  expect(await page.evaluate(() => localStorage.getItem('gistats_timer_start'))).not.toBeNull();
});
