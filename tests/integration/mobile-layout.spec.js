const { test, expect } = require('@playwright/test');
const { fillEntry } = require('./helpers');

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

// Pixel 8a (412px) and iPhone-sized (390px) phones: Save, Reset, timer and Urgency share one row.
// Narrower phones wrap instead (see the first spec); nothing may overflow the card.
for (const width of [390, 412]) {
  test(`entry form action buttons share one row at ${width}px wide`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await page.goto('/');
    await expect(page.locator('#entry-form-wrap')).toBeVisible();

    const tops = await page.evaluate(() =>
      ['#entry-form button[type=submit]', '#entry-form .btn-outline:not(#sync-now-btn)', '#timer-btn', '#urgency-btn']
        .map((sel) => Math.round(document.querySelector(sel).getBoundingClientRect().top))
    );

    expect(Math.max(...tops) - Math.min(...tops)).toBeLessThan(4);
  });
}

// The Save label swaps to "Saving…" mid-request. The row must not reflow when it does.
for (const width of [360, 375]) {
  test(`action buttons do not reflow while a save is in flight at ${width}px wide`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await page.goto('/');

    const positions = () =>
      page.evaluate(() =>
        ['#entry-form button[type=submit]', '#timer-btn', '#urgency-btn'].map((sel) => {
          const r = document.querySelector(sel).getBoundingClientRect();
          return [Math.round(r.left), Math.round(r.top)];
        })
      );
    const idle = await positions();

    await page.route('**/entries', async (route) => {
      if (route.request().method() === 'POST') {
        await new Promise((resolve) => setTimeout(resolve, 1500));
      }
      await route.continue();
    });
    await fillEntry(page);
    await page.click('#entry-form button[type=submit]');
    await expect(page.locator('#entry-form button[type=submit]')).toHaveText('Saving…', { useInnerText: true });

    // Row (top) must not change; Save keeps its width so the buttons beside it stay put.
    expect(await positions()).toEqual(idle);
  });
}

test('buttons keep their normal 14px text on a 412px phone', async ({ page }) => {
  await page.setViewportSize({ width: 412, height: 800 });
  await page.goto('/');

  for (const sel of ['#entry-form button[type=submit]', '#timer-btn', '#urgency-btn']) {
    await expect(page.locator(sel)).toHaveCSS('font-size', '14px');
  }
});
