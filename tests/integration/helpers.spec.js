const { test, expect } = require('@playwright/test');
const { uniqueOccurredAt } = require('./helpers');

test('uniqueOccurredAt returns distinct, well-formed values', () => {
  const values = Array.from({ length: 100 }, () => uniqueOccurredAt());

  expect(new Set(values).size).toBe(100);
  for (const v of values) {
    expect(v).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
  }
  expect(values[99] > values[0]).toBe(true);
});
