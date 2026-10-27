# Playwright Integration Tests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the ten rodney bash integration scripts with Playwright Test specs, using real browser offline emulation, with `mise run integration-test` unchanged as the entry point.

**Architecture:** Playwright's `webServer` seeds a throwaway SQLite DB in `tmp/` and runs PHP's built-in server against it. A `setup` project logs in once and saves storage state; one `*.spec.js` per old script runs serially in fresh contexts. Rodney files are deleted at the end.

**Tech Stack:** `@playwright/test` (Chromium only), Node (already present for Tailwind), PHP built-in server, mise.

**Spec:** `docs/specs/2026-10-26-playwright-integration-tests-design.md`

## Global Constraints

- Work on a feature branch `chore/playwright-integration-tests`; no git worktrees.
- Conventional commit messages.
- Test-first for a migration: every ported spec must be shown to FAIL under its mutation (listed per task) and PASS after reverting the mutation. Never commit a mutation.
- No application code changes (`views/`, `lib/`, etc.) survive in any commit.
- `.github/workflows/deploy.yml` changes only by the single rsync exclude in Task 1; integration tests are not added to CI.
- Playwright config: `workers: 1`, `fullyParallel: false`, `reuseExistingServer: false`, Chromium only, port `8765`.
- Test DB path is `tmp/integration.sqlite`; storage state is `tmp/.auth/admin.json` (both under the git-ignored `/tmp/`).
- Credentials: `GISTATS_USER` / `GISTATS_PASS`, defaults `admin` / `secret`.
- No fixed sleeps (`waitForTimeout`) anywhere in specs.
- Specs are plain CommonJS JavaScript (`require`), no TypeScript.
- Plan/spec docs are not user-facing prose; the README rewrite in Task 5 is.

## Review Focus

- Stale server on port 8765 (or the dev server) must not be silently tested against: Task 1 proves the run fails fast.
- Two entries created in the same minute must not collide on the unique `(user_id, occurred_at)` index: Task 1 tests `uniqueOccurredAt`.
- `playwright.config.js` must not be uploaded to production by the deploy workflow's rsync staging: Task 1 adds the exclude and checks a local staging run.
- A second consecutive run must pass, not trip on leftover DB rows: Task 5 runs the suite twice.
- Timer state must not leak between tests now that contexts are fresh: Task 3's `timer-start` asserts the key is absent on load.
- The development `database.sqlite` must be untouched by a run: Task 5 compares its mtime before and after.

---

### Task 1: Scaffolding and `offline-banner` (the offline-emulation spike)

**Files:**
- Modify: `package.json` (devDependency, `test:integration` script), `mise.toml` (`integration-test` task), `.gitignore`, `.github/workflows/deploy.yml` (rsync exclude only)
- Create: `playwright.config.js`, `tests/integration/seed-db.php`, `tests/integration/auth.setup.js`, `tests/integration/helpers.js`, `tests/integration/helpers.spec.js`, `tests/integration/offline-banner.spec.js`
- Behavior source (read, do not change): `views/layout.php`

**Interfaces:**
- Produces, in `helpers.js`:
  - `uniqueOccurredAt(): string`. Returns a `YYYY-MM-DDTHH:mm` string, never the same twice in one process. Starts at `2090-01-01T00:00` and adds one minute per call, so later calls sort first in the DESC entries list.
  - `nowLocal(): string`. Returns the current local time as `YYYY-MM-DDTHH:mm`.
  - `fillEntry(page, { occurredAt = uniqueOccurredAt(), stoolType = '4' } = {}): Promise<void>`. Sets `[name="occurred_at"]` and `#stool-type-input` via `page.evaluate` (these inputs are not filled by typing in the old scripts either).
- Produces: project names `setup` and `chromium`; every later spec starts already logged in at `baseURL` (`http://localhost:8765`).

- [ ] **Step 1: Create the branch and install**

  `git switch -c chore/playwright-integration-tests`; `npm i -D @playwright/test`; `npx playwright install chromium`. Add `tests/integration`-adjacent output dirs `/test-results/` and `/playwright-report/` to `.gitignore`. Add `"test:integration": "playwright test"` to `package.json` scripts.

- [ ] **Step 2: Write the failing tests**

  In `helpers.spec.js` (a plain Playwright test, no page): `uniqueOccurredAt returns distinct, well-formed values` calls it 100 times, expects 100 distinct values each matching `/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/`, and expects the last to sort after the first. In `offline-banner.spec.js`: `offline banner shows and read controls disable when offline, hides when online`:
  - `page.goto('/')`, expect `#entry-form-wrap` visible
  - `await context.setOffline(true)`; expect `#offline-banner` visible; expect `page.locator('[data-offline-disable]').first()` toHaveClass `/offline-disabled/`
  - `await context.setOffline(false)`; expect `#offline-banner` toHaveCSS `display` `none`

- [ ] **Step 3: Run to verify they fail**

  Run: `npx playwright test`
  Expected: FAIL (config missing / helpers undefined).

- [ ] **Step 4: Implement the scaffold**

  - `seed-db.php`: takes DB path as `$argv[1]`; same logic as the top of `tests/integration/run.php` (`DB::init`, `DB::createSchema`, insert `admin` with `password_hash('secret', PASSWORD_BCRYPT)`, `DB::reset()`). Exit 1 with a stderr message if no path.
  - `playwright.config.js` with the Global Constraints values: `testDir: 'tests/integration'`, `use.baseURL`, `trace: 'retain-on-failure'`, `reporter: 'list'`, `webServer.url: 'http://localhost:8765/login'`, `webServer.command` exactly the one-liner in the spec's "Server and database" section, and projects `setup` (`testMatch: /auth\.setup\.js/`) and `chromium` (`testMatch: /.*\.spec\.js/`, `dependencies: ['setup']`, `devices['Desktop Chrome']`, `storageState: 'tmp/.auth/admin.json'`).
  - `auth.setup.js`: one `setup` test that goes to `/login`, fills `[name="username"]` / `[name="password"]` from env (defaults above), clicks `[type="submit"]`, expects `#entry-form-wrap` visible, then `page.context().storageState({ path: 'tmp/.auth/admin.json' })`.
  - `helpers.js` per Interfaces.
  - `mise.toml`: `integration-test` run becomes `npx playwright test`; description "Run the Playwright browser integration tests against an isolated PHP server".

- [ ] **Step 5: Run to verify they pass**

  Run: `npx playwright test`
  Expected: `setup`, `helpers`, and `offline-banner` all PASS. If `setOffline` does not produce the banner, apply the spec's fallback (dispatch `offline` / `online` via `page.evaluate`), add a comment saying why, and record the deviation in the task's commit body. The remaining offline tasks depend on this outcome.

- [ ] **Step 6: Verify stale-server protection**

  In another terminal run `php -S localhost:8765 -t /tmp`, then `npx playwright test`.
  Expected: FAIL fast with Playwright's "port already used" error (not a pass against the wrong server). Stop the stray server.

- [ ] **Step 7: Mutation check for `offline-banner`**

  In `views/layout.php`, temporarily remove the code that shows `#offline-banner` on the `offline` event. Run `npx playwright test offline-banner`; expected FAIL on the banner assertion. `git checkout views/layout.php`; expected PASS.

- [ ] **Step 8: Keep the config out of production**

  In `.github/workflows/deploy.yml`, add `--exclude playwright.config.js \` to the `rsync` in "Stage production files" (next to `--exclude phpunit.xml`). No other workflow change is needed: the `test` job runs only lint and PHPUnit, and `npm ci` in the `deploy` job installs `@playwright/test` without downloading browsers. Verify locally by running the same `rsync` command into a scratch dir (`mkdir -p /tmp/dist-check && rsync -a ./ /tmp/dist-check/ <same flags>`, with `dist/` swapped for `/tmp/dist-check/`) and confirming `ls /tmp/dist-check` shows neither `playwright.config.js` nor `tests/`. Then confirm `npm ci` succeeds (lockfile in sync).

- [ ] **Step 9: Commit**

  ```bash
  git add package.json package-lock.json mise.toml .gitignore .github/workflows/deploy.yml playwright.config.js tests/integration/{seed-db.php,auth.setup.js,helpers.js,helpers.spec.js,offline-banner.spec.js}
  git commit -m "chore: add Playwright integration test scaffold with offline-banner spec"
  ```

---

### Task 2: Port `offline-queue` and `offline-sync`

**Files:**
- Create: `tests/integration/offline-queue.spec.js`, `tests/integration/offline-sync.spec.js`
- Behavior source (read, do not change): `views/layout.php`, `views/partials/entry-form.php`

**Interfaces:**
- Consumes: `fillEntry`, `uniqueOccurredAt` from `helpers.js`; logged-in fixture from Task 1.

- [ ] **Step 1: Write the failing tests**

  `offline-queue.spec.js`: `a submit made while offline is queued in localStorage`:
  - goto `/`, evaluate `localStorage.removeItem('gistats_pending')`
  - `context.setOffline(true)`, `fillEntry(page)`, click `#entry-form button[type=submit]`
  - `expect.poll` on `JSON.parse(localStorage.getItem('gistats_pending') || '[]').length` toBe `1`
  - expect `#pending-indicator` toHaveText `1 queued`

  `offline-sync.spec.js`: `a queued entry syncs when the browser comes back online`:
  - goto `/`, `context.setOffline(true)`
  - evaluate `localStorage.setItem('gistats_pending', JSON.stringify([{ occurred_at, duration: '05:00', stool_type: '4', note: 'integration test' }]))` with `occurred_at = uniqueOccurredAt()`
  - `context.setOffline(false)`
  - `expect.poll` queue length toBe `0`; expect `#flash-msg` toBeVisible

  If Task 1 used the fallback, mirror it here (dispatch the event; for the queue test, dispatch `htmx:sendError` on `#entry-form` as the old script did) and comment why.

- [ ] **Step 2: Run to verify they pass against the unmodified app**

  Run: `npx playwright test offline-queue offline-sync`
  Expected: PASS. (Ports of existing behavior are green first; the mutation checks below supply the red.)

- [ ] **Step 3: Mutation checks**

  - `offline-queue`: in the entry form's send-error handler, temporarily stop writing to `gistats_pending`. Expected FAIL on the queue length. Revert, expect PASS.
  - `offline-sync`: in the `online` handler, temporarily skip the sync call. Expected FAIL (queue stays at 1). Revert, expect PASS.

- [ ] **Step 4: Commit**

  ```bash
  git add tests/integration/offline-queue.spec.js tests/integration/offline-sync.spec.js
  git commit -m "test: port offline queue and sync integration tests to Playwright"
  ```

---

### Task 3: Port the four timer specs

**Files:**
- Create: `tests/integration/timer-start.spec.js`, `timer-stop.spec.js`, `timer-resume.spec.js`, `timer-submit.spec.js` (all under `tests/integration/`)
- Behavior source (read, do not change): `views/partials/entry-form.php`

**Interfaces:**
- Consumes: `fillEntry`, `uniqueOccurredAt` from `helpers.js`.
- Storage key: `gistats_timer_start` (epoch ms as string). Elements: `#timer-btn` (`■` running, `▶` stopped), `[name="duration"]` (read-only while running).

- [ ] **Step 1: Write the tests**

  - `timer-start.spec.js`, `tapping the timer button starts the timer`: goto `/`; expect `localStorage.getItem('gistats_timer_start')` null (fresh context, no leak); click `#timer-btn`; expect `#timer-btn` toHaveText `■`, `[name="duration"]` toHaveJSProperty `readOnly` `true`, key not null.
  - `timer-resume.spec.js`, `a running timer resumes after reload`: goto `/`; set key to `Date.now() - 30000`; `page.reload()`; same three assertions as start.
  - `timer-stop.spec.js`, `stopping the timer writes elapsed duration`: set key to `Date.now() - 65000`; reload; expect `■` and read-only; click `#timer-btn`; expect `▶`, `readOnly` false, key null, and duration value matching `/^01:/`.
  - `timer-submit.spec.js`, `submitting while the timer runs stops it`: set key to `Date.now() - 90000`; reload; `fillEntry(page)`; click `button.btn-primary`; `expect.poll` key toBeNull.

  Use `page.evaluate` to set the key. Use `toHaveText` / `expect.poll` for all waits.

- [ ] **Step 2: Run to verify they pass against the unmodified app**

  Run: `npx playwright test timer-`
  Expected: 4 PASS.

- [ ] **Step 3: Mutation checks (one per spec, revert each)**

  - start: make the click handler not write the storage key. Expect FAIL.
  - resume: make page load ignore the stored key. Expect FAIL.
  - stop: make stop skip writing the duration. Expect FAIL on `/^01:/`.
  - submit: make submit leave the key in place. Expect FAIL.

- [ ] **Step 4: Commit**

  ```bash
  git add tests/integration/timer-*.spec.js
  git commit -m "test: port timer integration tests to Playwright"
  ```

---

### Task 4: Port `calendar-updates-on-entry`, `save-feedback`, `urgency-toggle`

**Files:**
- Create: `tests/integration/calendar-updates-on-entry.spec.js`, `save-feedback.spec.js`, `urgency-toggle.spec.js`
- Behavior source (read, do not change): `views/partials/entry-form.php`, `views/layout.php`

**Interfaces:**
- Consumes: `fillEntry`, `uniqueOccurredAt`, `nowLocal` from `helpers.js`.

- [ ] **Step 1: Write the tests**

  - `calendar-updates-on-entry`: `the calendar shows a new entry without a reload`. Count `#calendar-wrap img[alt^="Type"]` as `before`; `fillEntry(page, { occurredAt: nowLocal() })` (current month is required); click `button.btn-primary`; expect the count toBe `before + 1` using `expect.poll`. Also assert no navigation happened (`page.on('framenavigated')` counter stays 0 after load).
  - `save-feedback`: `Save is disabled and reads Saving… while the request is in flight`. Idle: `#entry-form button[type=submit]` enabled, text `Save`. Add `page.route` for the entry POST that waits 1500 ms, then `route.continue()`. `fillEntry(page)`, click; expect button disabled with text `Saving…`; then expect `#flash-msg` toHaveText `Saved!`, button enabled, text `Save`.
  - `urgency-toggle`: `urgency toggle persists through create and edit`. Default: `#urgency-input` value `0`, `#urgency-btn` lacks class `active`. Click `#urgency-btn`: value `1`, has `active`. `fillEntry(page)` and submit via `button.btn-primary`. Click the first `[hx-get*="/edit"]`; expect `#urgency-input` value `1` and `#urgency-btn` has `active`. The edit link wait is `toBeVisible` on the locator (no sleep).

- [ ] **Step 2: Run to verify they pass against the unmodified app**

  Run: `npx playwright test calendar save-feedback urgency`
  Expected: 3 PASS.

- [ ] **Step 3: Mutation checks (revert each)**

  - calendar: remove the out-of-band calendar swap from the add-entry response. Expect FAIL.
  - save-feedback: remove the code that disables the button / sets `Saving…`. Expect FAIL.
  - urgency: stop pre-loading the urgency value in the edit partial. Expect FAIL on the final assertions.

- [ ] **Step 4: Commit**

  ```bash
  git add tests/integration/{calendar-updates-on-entry,save-feedback,urgency-toggle}.spec.js
  git commit -m "test: port calendar, save-feedback and urgency integration tests to Playwright"
  ```

---

### Task 5: Remove rodney and update docs

**Files:**
- Delete: `tests/integration/run.php`, `tests/integration/config.sh`, `tests/integration/test-*.sh`
- Modify: `README.md` (integration section near line 137), `.gitignore` (remove `.rodney/` and the `# Rodney` comment), `AGENTS.md` if it mentions rodney or `run.php`
- Review (do not necessarily modify): historical `docs/specs/` and `docs/plans/` files mentioning rodney stay as they are

- [ ] **Step 1: Delete the old files**

  `git rm tests/integration/run.php tests/integration/config.sh tests/integration/test-*.sh`

- [ ] **Step 2: Rewrite the README integration section**

  Use the `writing-tracy` skill (this is user-facing prose). Cover: what the tests do, that they use an isolated DB, one-time `npx playwright install chromium`, `mise run integration-test`, and `npx playwright test --ui` / `npx playwright show-report` for debugging.

- [ ] **Step 3: Clean-checkout style verification**

  - `grep -ri rodney . --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=.git --exclude-dir=docs` returns nothing.
  - Record `stat -f %m database.sqlite`. Run `mise run integration-test` twice in a row. Expected: both runs fully PASS (second run proves no leftover-DB failures), and the mtime is unchanged.
  - `mise run lint` and `mise run test` (PHPUnit) still pass.

- [ ] **Step 4: Commit and finish**

  ```bash
  git add -A
  git commit -m "chore: remove rodney integration scripts in favor of Playwright"
  ```

  Then use `superpowers:finishing-a-development-branch`.
