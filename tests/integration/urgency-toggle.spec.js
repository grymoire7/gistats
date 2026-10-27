const { test, expect } = require('@playwright/test');
const { fillEntry } = require('./helpers');

test('urgency toggle persists through create and edit', async ({ page }) => {
  await page.goto('/');
  const input = page.locator('#urgency-input');
  const button = page.locator('#urgency-btn');

  // Default: off.
  await expect(input).toHaveValue('0');
  await expect(button).not.toHaveClass(/active/);

  // Toggle on.
  await button.click();
  await expect(input).toHaveValue('1');
  await expect(button).toHaveClass(/active/);

  // uniqueOccurredAt sorts newest-first, so this entry's edit link is the first one.
  await fillEntry(page);
  await page.click('button.btn-primary');

  const editLink = page.locator('[hx-get*="/edit"]').first();
  await expect(editLink).toBeVisible();
  await editLink.click();

  await expect(input).toHaveValue('1');
  await expect(button).toHaveClass(/active/);
});
