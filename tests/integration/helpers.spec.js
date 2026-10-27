const { test, expect } = require('@playwright/test');
const { uniqueOccurredAt, nowLocal } = require('./helpers');

test('uniqueOccurredAt returns distinct, well-formed values', () => {
  const values = Array.from({ length: 100 }, () => uniqueOccurredAt());

  expect(new Set(values).size).toBe(100);
  for (const v of values) {
    expect(v).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
  }
  expect(values[99] > values[0]).toBe(true);
});

test('nowLocal is expressed in the app timezone, not the machine timezone', () => {
  const inAppZone = () =>
    new Intl.DateTimeFormat('sv-SE', {
      timeZone: 'America/Chicago',
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).format(new Date()).replace(' ', 'T');

  // Sample either side of the call so a minute rollover cannot fail the test.
  const before = inAppZone();
  const actual = nowLocal();
  const after = inAppZone();

  expect([before, after]).toContain(actual);
});
