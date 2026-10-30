const { test, expect } = require('@playwright/test');
const { uniqueOccurredAt, fillEntry } = require('./helpers');

test('a queued entry syncs when the browser comes back online', async ({ page, context }) => {
  await page.goto('/');
  await context.setOffline(true);

  await page.evaluate((occurredAt) => {
    localStorage.setItem(
      'gistats_pending',
      JSON.stringify([{ occurred_at: occurredAt, duration: '05:00', stool_type: '4', note: 'integration test', urgency: '0' }])
    );
  }, uniqueOccurredAt());

  await context.setOffline(false);

  await expect
    .poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('gistats_pending') || '[]').length))
    .toBe(0);
  await expect(page.locator('#flash-msg')).toHaveText(/1 entry synced/);
});

test('a queued entry syncs when the app is opened already online', async ({ page }) => {
  // The common mobile case: the phone reconnected while the app was closed, so
  // the page loads online and the window 'online' event never fires. Seeding
  // the queue while online (rather than going offline first) keeps this
  // deterministic — the old page's online-event sync can't race the reload.
  await page.goto('/');
  await page.evaluate((occurredAt) => {
    localStorage.setItem(
      'gistats_pending',
      JSON.stringify([{ occurred_at: occurredAt, duration: '05:00', stool_type: '4', note: 'opened already online', urgency: '0' }])
    );
  }, uniqueOccurredAt());

  await page.reload();

  await expect
    .poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('gistats_pending') || '[]').length))
    .toBe(0);
  await expect(page.locator('#flash-msg')).toHaveText(/1 entry synced/);
});

test('an urgent entry queued offline keeps its urgency through sync', async ({ page, context }) => {
  await page.goto('/');
  await page.evaluate(() => localStorage.removeItem('gistats_pending'));

  await context.setOffline(true);
  await fillEntry(page);
  await page.click('#urgency-btn');
  await page.click('#entry-form button[type=submit]');

  await expect
    .poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('gistats_pending') || '[]').length))
    .toBe(1);
  await expect
    .poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('gistats_pending'))[0].urgency))
    .toBe('1');

  await context.setOffline(false);

  await expect
    .poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('gistats_pending') || '[]').length))
    .toBe(0);
  await expect(page.locator('#flash-msg')).toHaveText(/1 entry synced/);

  // The sync posts a raw fetch, so the entry list needs a reload to show it.
  // Far-future timestamps sort first (DESC).
  await page.reload();
  await expect(page.locator('tr[id^="entry-"]').first().locator('span[title="Urgent"]')).toBeVisible();
});
