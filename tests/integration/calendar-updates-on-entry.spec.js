const { test, expect } = require('@playwright/test');
const { fillEntry, nowLocal } = require('./helpers');

test('the calendar shows a new entry without a reload', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('#entry-form-wrap')).toBeVisible();

  const typeImages = page.locator('#calendar-wrap img[alt^="Type"]');
  const before = await typeImages.count();

  let navigations = 0;
  page.on('framenavigated', () => navigations++);

  // Today, so the entry falls in the month the calendar is showing.
  await fillEntry(page, { occurredAt: nowLocal() });
  await page.click('button.btn-primary');

  // The calendar updates through an out-of-band swap, not a page load.
  await expect(typeImages).toHaveCount(before + 1);
  expect(navigations).toBe(0);
});
