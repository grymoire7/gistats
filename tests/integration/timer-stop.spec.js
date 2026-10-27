const { test, expect } = require('@playwright/test');

test('stopping the timer writes elapsed duration', async ({ page }) => {
  await page.goto('/');
  await page.evaluate(() => localStorage.setItem('gistats_timer_start', (Date.now() - 65000).toString()));
  await page.reload();

  await expect(page.locator('#timer-btn')).toHaveText('■');
  await expect(page.locator('[name="duration"]')).toHaveJSProperty('readOnly', true);

  await page.click('#timer-btn');

  await expect(page.locator('#timer-btn')).toHaveText('▶');
  await expect(page.locator('[name="duration"]')).toHaveJSProperty('readOnly', false);
  expect(await page.evaluate(() => localStorage.getItem('gistats_timer_start'))).toBeNull();
  // 65 seconds elapsed: 01:05
  await expect(page.locator('[name="duration"]')).toHaveValue(/^01:/);
});
