const { test, expect } = require('@playwright/test');
const { fillEntry } = require('./helpers');

test('Save is disabled and reads Saving… while the request is in flight', async ({ page }) => {
  await page.goto('/');
  const save = page.locator('#entry-form button[type=submit]');

  // Idle: enabled, labelled Save.
  await expect(save).toBeEnabled();
  await expect(save).toHaveText('Save', { useInnerText: true });

  // Hold the POST open so the in-flight state can be observed.
  await page.route('**/entries', async (route) => {
    if (route.request().method() === 'POST') {
      await new Promise((resolve) => setTimeout(resolve, 1500));
    }
    await route.continue();
  });

  await fillEntry(page);
  await save.click();

  await expect(save).toBeDisabled();
  await expect(save).toHaveText('Saving…', { useInnerText: true });

  // After the response: flash shown, fresh form, Save enabled again.
  await expect(page.locator('#flash-msg')).toHaveText('Saved!');
  await expect(save).toBeEnabled();
  await expect(save).toHaveText('Save', { useInnerText: true });
});
