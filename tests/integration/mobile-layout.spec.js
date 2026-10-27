const { test, expect } = require('@playwright/test');

// Narrow phones: iPhone SE / mini and small Androids.
for (const width of [320, 360, 375]) {
  test(`entry form buttons stay inside the card at ${width}px wide`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await page.goto('/');
    await expect(page.locator('#entry-form-wrap')).toBeVisible();

    const { pageOverflow, escaped } = await page.evaluate(() => {
      const cardRight = document.getElementById('entry-form-wrap').getBoundingClientRect().right;
      const escaped = [...document.querySelectorAll('#entry-form button')]
        .filter((b) => b.offsetParent !== null) // skip hidden buttons (e.g. Sync now)
        .filter((b) => b.getBoundingClientRect().right > cardRight + 0.5)
        .map((b) => b.id || b.textContent.trim());
      return { pageOverflow: document.documentElement.scrollWidth - window.innerWidth, escaped };
    });

    expect(escaped).toEqual([]);
    expect(pageOverflow).toBeLessThanOrEqual(0);
  });
}

test('the Urgency label stays on one line on a narrow phone', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 800 });
  await page.goto('/');

  const lines = await page.locator('#urgency-btn').evaluate((el) => {
    const lineHeight = parseFloat(getComputedStyle(el).lineHeight) || parseFloat(getComputedStyle(el).fontSize) * 1.2;
    const padding = parseFloat(getComputedStyle(el).paddingTop) + parseFloat(getComputedStyle(el).paddingBottom);
    return Math.round((el.getBoundingClientRect().height - padding) / lineHeight);
  });

  expect(lines).toBe(1);
});
