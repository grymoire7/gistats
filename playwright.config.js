const { defineConfig, devices } = require('@playwright/test');

const PORT = 8765;
const DB = 'tmp/integration.sqlite';

module.exports = defineConfig({
  testDir: 'tests/integration',
  // All specs share one database, so run them one at a time.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: `http://localhost:${PORT}`,
    trace: 'retain-on-failure',
  },
  webServer: {
    command:
      `rm -f ${DB} && php tests/integration/seed-db.php ${DB} && ` +
      `GISTATS_DB_PATH="$PWD/${DB}" exec php -S localhost:${PORT} tests/integration/server-router.php`,
    url: `http://localhost:${PORT}/login`,
    // Never test against a stray server (or the dev server) that happens to be running.
    reuseExistingServer: false,
    // php -S logs every request to stderr; keep the run output readable.
    stderr: 'ignore',
    timeout: 15000,
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.js/ },
    {
      name: 'chromium',
      testMatch: /.*\.spec\.js/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: 'tmp/.auth/admin.json' },
    },
  ],
});
