# Playwright Integration Tests: Design

## Problem

The browser integration tests are ten bash scripts (`tests/integration/test-*.sh`, about 500 lines) driven by `rodney`, wrapped by `tests/integration/run.php`. As the collection grows, four problems stand out:

- **Boilerplate.** Every script repeats the same `rodney start --local` / `trap rodney stop` / login block.
- **Sleeps.** Waits are `rodney sleep 0.2` to `2`. They are guesses, and they will turn into flakes as the suite grows.
- **Fake offline.** The four offline scripts dispatch synthetic `offline`/`online` events and a hand-built `htmx:sendError`. They never exercise the browser's real offline path.
- **Shared state.** Tests share one Chrome profile and one database. Scripts clean up `localStorage` by hand ("so stale timer state doesn't leak") and pick unique `occurred_at` values by hand (2098, 2099) to dodge the unique `(user_id, occurred_at)` index.

## Decision

Migrate to Playwright Test (`@playwright/test`, Chromium only). A PHP equivalent was considered and rejected:

- Laravel Dusk is tied to Laravel.
- Symfony Panther and Codeception use WebDriver, with weaker auto-waiting and no first-class offline emulation. The offline tests are the most valuable ones, so that is the wrong trade.

Node is already a dev dependency (Tailwind), so the cost is one more npm dev dependency and a Chromium download.

## Goals

- Same coverage as today's ten scripts, expressed as Playwright tests.
- Offline tests use real browser offline emulation (`context.setOffline`) instead of synthetic events.
- No fixed sleeps. Waiting is done by Playwright auto-wait and web-first assertions.
- Login happens once per run, not once per test.
- Each test gets a fresh browser context (no leaked `localStorage`).
- `mise run integration-test` stays the single entry point.
- Never touch the development database.

## Non-goals

- No new test coverage beyond the ten existing scripts, except where the real offline path replaces a synthetic one.
- No cross-browser matrix, no visual regression, and no integration job in CI. `deploy.yml` runs only lint and PHPUnit today and stays that way.
- No parallel execution in this migration (see Isolation).
- No changes to application code.

## Design

### Layout

```
playwright.config.js                  project root
tests/integration/
  auth.setup.js                       logs in once, saves storage state
  helpers.js                          shared helpers (uniqueOccurredAt, fillEntry)
  seed-db.php                         creates schema and the admin user
  server-router.php                   unchanged
  calendar-updates-on-entry.spec.js   one spec per current script
  offline-banner.spec.js
  offline-queue.spec.js
  offline-sync.spec.js
  save-feedback.spec.js
  timer-resume.spec.js
  timer-start.spec.js
  timer-stop.spec.js
  timer-submit.spec.js
  urgency-toggle.spec.js
```

Removed at the end: `run.php`, `config.sh`, all `test-*.sh`, the `.rodney/` ignore entry.

### Server and database

Playwright's `webServer` starts the app on a fixed port (8765, same default as `run.php`). The command deletes any old temp DB, seeds a new one with `seed-db.php`, then `exec`s `php -S` so Playwright can stop it cleanly:

```
rm -f tmp/integration.sqlite && php tests/integration/seed-db.php tmp/integration.sqlite && GISTATS_DB_PATH="$PWD/tmp/integration.sqlite" exec php -S localhost:8765 tests/integration/server-router.php
```

`seed-db.php` takes the DB path as `argv[1]` and does what the top of `run.php` does today: `DB::init`, `DB::createSchema`, insert `admin` / `secret`. It lives in `tmp/` (already git-ignored). `reuseExistingServer` is `false`, so a stray dev server on the same port can never be tested against by accident.

### Login

A `setup` project runs `auth.setup.js` first: it logs in through the real form and saves `storageState` to `tmp/.auth/admin.json`. The browser project depends on `setup` and uses that state. Each test starts from a new context that already has the session cookie, so tests begin on `/` and the repeated login block disappears. Credentials come from `GISTATS_USER` / `GISTATS_PASS`, defaulting to `admin` / `secret`, as in `config.sh`.

### Isolation

Tests share one database, so `workers: 1` and `fullyParallel: false` for now. The two data hazards are handled explicitly:

- **Unique `occurred_at`.** `helpers.js` exports `uniqueOccurredAt(): string`, which returns a `YYYY-MM-DDTHH:mm` value never returned before in this run. This replaces the hand-picked 2098/2099 dates and the `$NOW` collisions.
- **Order-dependent assertions.** The calendar test currently asserts "zero entries" because it runs first on a fresh DB. The port records the entry count before submit and asserts it increases by one.

Parallelism can come later because the only blocker is the shared DB. Do not build for it now.

### Offline

- `offline-banner`: `context.setOffline(true)`, then expect `#offline-banner` visible and the first `[data-offline-disable]` to have class `offline-disabled`. `setOffline(false)` must hide the banner (`display: none`). Playwright's emulation makes Chromium fire the real `offline` and `online` window events. The first task confirms this before any other offline test is ported.
- `offline-queue`: go offline, fill and submit the entry form, and expect the real failed request to queue one item in `localStorage.gistats_pending` and `#pending-indicator` to read `1 queued`. This replaces the synthetic `htmx:sendError`.
- `offline-sync`: seed `gistats_pending` with one valid entry (using `uniqueOccurredAt()`), go offline then online, and expect the queue to drain to `[]` and `#flash-msg` to appear.

If real offline emulation turns out not to reproduce the behavior in a way a test can rely on, that test falls back to dispatching the event and says why in a comment. The fallback is a deviation to report, not a silent change.

### Test-first discipline for a migration

The project requires red-green-refactor. For a port, the "red" is a mutation check: after each ported test passes, temporarily break the app behavior it covers, confirm the test fails for the right reason, revert, and confirm it passes again. A port that cannot be made to fail is not done. The mutation target for each test is listed in the plan.

### Command surface

- `mise run integration-test` runs `npx playwright test`.
- `npm run test:integration` is added for parity with the other npm scripts.
- `mise.toml` description is updated; the README integration section is rewritten to describe Playwright, `npx playwright install chromium` as one-time setup, and `npx playwright show-report` / `--ui` for debugging.

## Test inventory

| Existing script | New spec | Key assertions |
|---|---|---|
| test-calendar-updates-on-entry | calendar-updates-on-entry | `#calendar-wrap img[alt^="Type"]` count rises by one after submit, no reload |
| test-offline-banner | offline-banner | banner shows offline, controls get `offline-disabled`, banner hides online |
| test-offline-queue | offline-queue | real failed submit queues 1 item, indicator `1 queued` |
| test-offline-sync | offline-sync | queue drains to 0, `#flash-msg` appears |
| test-save-feedback | save-feedback | idle `Save` enabled; in flight `Saving…` disabled; after response `Saved!` and `Save` enabled |
| test-timer-resume | timer-resume | stored start 30s ago: button `■`, duration read-only, key present after reload |
| test-timer-start | timer-start | click `#timer-btn`: `■`, read-only duration, key set |
| test-timer-stop | timer-stop | stored start 65s ago: stop gives `▶`, editable duration matching `^01:`, key removed |
| test-timer-submit | timer-submit | submit while running removes the key |
| test-urgency-toggle | urgency-toggle | defaults off; toggles on; edit form preloads on |

For `save-feedback`, the in-flight state is held open with `page.route` delaying the POST instead of monkey-patching `XMLHttpRequest.prototype.send`.

### Deploy workflow

`.github/workflows/deploy.yml` was checked and needs one change. Its rsync staging step excludes tooling files by name (`phpunit.xml`, `mise.toml`, `package*.json`, `tests/`, and so on), so a root-level `playwright.config.js` would be uploaded to production. Add `--exclude playwright.config.js`. Nothing else changes:

- The `test` job runs lint and PHPUnit only, and never ran the rodney scripts.
- `npm ci` in the `deploy` job will also install `@playwright/test`. That adds install time but downloads no browsers, and it requires `package-lock.json` to be committed in sync.
- `test-results/` and `playwright-report/` are git-ignored and absent from a CI checkout.

## Risks

- **Emulated offline may not match the app's expectations.** Mitigated by making it the first task and defining the fallback above.
- **Chromium download (~150 MB)** for each developer machine. One-time cost, documented in the README.
- **Port 8765 busy.** Playwright fails fast with a clear error, since `reuseExistingServer` is off.
- **Selectors tied to markup.** Ported as-is from the scripts; moving to role/label locators is out of scope.

## Success criteria

- `mise run integration-test` passes on a clean checkout after `npm install` and `npx playwright install chromium`.
- Every spec fails under its listed mutation and passes after revert.
- No `rodney` references remain in the repo outside historical `docs/` files.
- The development database is unchanged after a run.
