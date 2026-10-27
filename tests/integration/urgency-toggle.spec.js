const { test, expect } = require('@playwright/test');
const { fillEntry, uniqueOccurredAt } = require('./helpers');

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

  // Hold the POST open: earlier specs' entries already have edit links on the page,
  // so the spec has to wait for this entry's save before reaching for one.
  await page.route('**/entries', async (route) => {
    if (route.request().method() === 'POST') {
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    await route.continue();
  });

  const occurredAt = uniqueOccurredAt();
  await fillEntry(page, { occurredAt });
  await page.click('button.btn-primary');

  // Saved: the list now has this entry on top (newest first), so its edit link is the first one.
  await expect(page.locator('#flash-msg')).toHaveText('Saved!');
  const editLink = page.locator('[hx-get*="/edit"]').first();
  await expect(editLink).toBeVisible();
  await editLink.click();

  // The edit form posts to /entries/<id>; the create form posts to /entries. Wait for the
  // swap, then check it is the entry we just saved and not an older one.
  await expect(page.locator('#entry-form')).toHaveAttribute('hx-post', /\/entries\/\d+$/);
  await expect(page.locator('[name="occurred_at"]')).toHaveValue(occurredAt);
  await expect(input).toHaveValue('1');
  await expect(button).toHaveClass(/active/);
});
