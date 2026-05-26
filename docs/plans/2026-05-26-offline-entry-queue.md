# Offline Entry Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Queue new entry form submissions in `localStorage` when offline and replay them automatically (or manually) when connectivity is restored, while disabling read-only HTMX controls and showing an offline banner.

**Architecture:** Three concerns handled by a single inline JS block in `layout.php`: a connectivity UI layer driven by `window online`/`offline` events, a write queue triggered by `htmx:sendError` on the new-entry form, and a sync path that fetches a fresh CSRF token then POSTs each queued item sequentially. A new `GET /csrf-token` PHP endpoint supports the sync path.

**Tech Stack:** PHP/SQLite backend, HTMX v2 (`htmx:sendError`), vanilla JS, `localStorage`, PHPUnit for backend tests, rodney bash scripts for frontend integration tests.

---

## File Map

| File | Action | What changes |
|---|---|---|
| `index.php` | Modify | Add `GET /csrf-token` route |
| `views/layout.php` | Modify | Add offline banner div + offline JS block |
| `views/partials/entry-form.php` | Modify | Add pending indicator + sync button |
| `views/partials/calendar.php` | Modify | Add `data-offline-disable` to nav buttons + day cells with entries |
| `views/partials/event-list.php` | Modify | Add `data-offline-disable` to show-all link |
| `views/partials/event-rows.php` | Modify | Add `data-offline-disable` to load-more button |
| `css/input.css` | Modify | Add `.offline-disabled` class |
| `tests/OfflineCsrfTokenTest.php` | Create | PHPUnit tests for CSRF endpoint contract |
| `tests/integration/test-offline-banner.sh` | Create | Rodney: banner + control disabling |
| `tests/integration/test-offline-queue.sh` | Create | Rodney: entry queues to localStorage |
| `tests/integration/test-offline-sync.sh` | Create | Rodney: queue drains on reconnect |

---

## Task 1: GET /csrf-token — backend endpoint (TDD)

**Files:**
- Create: `tests/OfflineCsrfTokenTest.php`
- Modify: `index.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/OfflineCsrfTokenTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

class OfflineCsrfTokenTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testCsrfTokenIsA64CharHexString(): void
    {
        $token = csrf_token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testCsrfTokenIsStableWithinSession(): void
    {
        $first  = csrf_token();
        $second = csrf_token();
        $this->assertSame($first, $second);
    }

    public function testEndpointJsonPayloadContainsToken(): void
    {
        $token   = csrf_token();
        $payload = json_encode(['token' => $token]);
        $decoded = json_decode($payload, true);
        $this->assertSame($token, $decoded['token']);
    }

    public function testUnauthenticatedSessionIsNotLoggedIn(): void
    {
        $this->assertFalse(is_logged_in());
    }

    public function testAuthenticatedSessionIsLoggedIn(): void
    {
        $_SESSION['user_id'] = 1;
        $this->assertTrue(is_logged_in());
    }
}
```

- [ ] **Step 2: Run tests and confirm they fail**

```bash
vendor/bin/phpunit tests/OfflineCsrfTokenTest.php
```

Expected: 5 tests fail with class/function-not-found errors (the test file can't be loaded yet because the route doesn't exist — but the lib functions do, so actually these tests will mostly pass; that's fine, they establish the contract before the route is written).

Actually expected: all 5 pass immediately — these test the lib functions, which already exist. Confirm all 5 pass before adding the route.

- [ ] **Step 3: Add the route to `index.php`**

Insert before the `$result = $router->dispatch(...)` line at the bottom of `index.php`:

```php
$router->get('/csrf-token', function () {
    if (!is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    header('Content-Type: application/json');
    echo json_encode(['token' => csrf_token()]);
});
```

- [ ] **Step 4: Run the full test suite to confirm nothing broke**

```bash
vendor/bin/phpunit
```

Expected: all tests pass (same count as before + 5 new ones from Task 1).

- [ ] **Step 5: Commit**

```bash
git add tests/OfflineCsrfTokenTest.php index.php
git commit -m "feat: add GET /csrf-token endpoint for offline sync"
```

---

## Task 2: .offline-disabled CSS class

**Files:**
- Modify: `css/input.css`

- [ ] **Step 1: Add the utility class to `css/input.css`**

Append to the end of `css/input.css`:

```css
.offline-disabled {
    opacity: 0.4;
    pointer-events: none;
}
```

- [ ] **Step 2: Rebuild compiled CSS**

```bash
npm run build:css
```

Expected: `css/compiled.css` updated with no errors.

- [ ] **Step 3: Commit**

```bash
git add css/input.css css/compiled.css
git commit -m "feat: add .offline-disabled utility class"
```

---

## Task 3: Pending indicator + sync button in entry-form.php (TDD)

**Files:**
- Modify: `tests/ViewPartialsTest.php`
- Modify: `views/partials/entry-form.php`

- [ ] **Step 1: Write the failing tests**

Add these two methods to `tests/ViewPartialsTest.php` inside the `ViewPartialsTest` class, after the existing test methods:

```php
public function testNewEntryFormHasPendingIndicator(): void
{
    $html = $this->renderEntryForm(null);
    $this->assertStringContainsString('id="pending-indicator"', $html);
}

public function testNewEntryFormHasSyncNowButton(): void
{
    $html = $this->renderEntryForm(null);
    $this->assertStringContainsString('id="sync-now-btn"', $html);
}
```

- [ ] **Step 2: Run to confirm they fail**

```bash
vendor/bin/phpunit tests/ViewPartialsTest.php
```

Expected: 2 failures — `pending-indicator` and `sync-now-btn` not found in rendered HTML.

- [ ] **Step 3: Add pending indicator and sync button to entry-form.php**

In `views/partials/entry-form.php`, after the closing `</div>` of the Save/Reset button row (line ~88, the `<div style="display:flex;gap:10px;">` block), add:

```php
        <div style="min-height:24px;display:flex;align-items:center;">
            <span id="pending-indicator"
                  style="display:none;font-size:12px;color:var(--color-muted);"></span>
            <button id="sync-now-btn" type="button" class="btn-outline"
                    style="display:none;font-size:12px;padding:4px 14px;margin-left:8px;"
                    onclick="syncQueue()">Sync now</button>
        </div>
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
vendor/bin/phpunit tests/ViewPartialsTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add tests/ViewPartialsTest.php views/partials/entry-form.php
git commit -m "feat: add pending indicator and sync button to entry form"
```

---

## Task 4: Offline banner and data-offline-disable attributes

**Files:**
- Modify: `views/layout.php`
- Modify: `views/partials/calendar.php`
- Modify: `views/partials/event-list.php`
- Modify: `views/partials/event-rows.php`

No PHPUnit tests for these — markup-only additions in non-partial templates. Covered by rodney tests in Task 6.

- [ ] **Step 1: Add offline banner to layout.php**

In `views/layout.php`, after `<div id="flash-area"></div>` (line 44), insert:

```html
    <!-- Offline banner -->
    <div id="offline-banner"
         style="display:none;background:var(--color-surface);border-bottom:1px solid var(--color-border);padding:10px 16px;text-align:center;font-size:13px;color:var(--color-muted);">
        You're offline. Entries will be queued and synced when you reconnect.
    </div>
```

- [ ] **Step 2: Add data-offline-disable to calendar nav buttons**

In `views/partials/calendar.php`, add `data-offline-disable` to both nav buttons (lines 15–18 and 20–23). The prev button becomes:

```php
        <button class="btn-outline" style="font-size:12px;padding:4px 12px;"
            data-offline-disable
            hx-get="<?= htmlspecialchars($baseUrl) ?>/calendar?year=<?= $prevYear ?>&month=<?= $prevMonth ?>"
            hx-target="#calendar-wrap"
            hx-swap="innerHTML">‹</button>
```

The next button becomes:

```php
        <button class="btn-outline" style="font-size:12px;padding:4px 12px;"
            data-offline-disable
            hx-get="<?= htmlspecialchars($baseUrl) ?>/calendar?year=<?= $nextYear ?>&month=<?= $nextMonth ?>"
            hx-target="#calendar-wrap"
            hx-swap="innerHTML">›</button>
```

- [ ] **Step 3: Add data-offline-disable to calendar day cells with entries**

In `views/partials/calendar.php`, on the day `<div>` element (line 39), add `data-offline-disable` when the day has entries. Change:

```php
        <div style="background:var(--color-canvas);border:<?= $border ?>;border-radius:5px;padding:3px;text-align:center;min-height:52px;<?= $day['count'] ? 'cursor:pointer;' : '' ?>"
            <?php if ($day['count']): ?>
            hx-get="<?= htmlspecialchars($baseUrl) ?>/entries?date=<?= htmlspecialchars($day['date']) ?>"
            hx-target="#entries-wrap"
            hx-swap="innerHTML"
            <?php endif; ?>>
```

To:

```php
        <div style="background:var(--color-canvas);border:<?= $border ?>;border-radius:5px;padding:3px;text-align:center;min-height:52px;<?= $day['count'] ? 'cursor:pointer;' : '' ?>"
            <?php if ($day['count']): ?>
            data-offline-disable
            hx-get="<?= htmlspecialchars($baseUrl) ?>/entries?date=<?= htmlspecialchars($day['date']) ?>"
            hx-target="#entries-wrap"
            hx-swap="innerHTML"
            <?php endif; ?>>
```

- [ ] **Step 4: Add data-offline-disable to the show-all link in event-list.php**

In `views/partials/event-list.php`, the "Show all" anchor (lines 19–23) becomes:

```php
        <a href="#"
           data-offline-disable
           hx-get="<?= htmlspecialchars($baseUrl) ?>/entries"
           hx-target="#entries-wrap"
           hx-swap="innerHTML"
           style="font-size:12px;color:var(--color-green);">Show all</a>
```

- [ ] **Step 5: Add data-offline-disable to the load-more button in event-rows.php**

In `views/partials/event-rows.php`, the load-more button (lines 54–57) becomes:

```php
        <button class="btn-outline"
            data-offline-disable
            hx-get="<?= htmlspecialchars($baseUrl) ?>/entries/more?offset=<?= $nextOffset ?><?= $filterDate ? '&date=' . urlencode($filterDate) : '' ?>"
            hx-target="#load-more-row"
            hx-swap="outerHTML">Load more</button>
```

- [ ] **Step 6: Run PHPUnit to confirm nothing is broken**

```bash
vendor/bin/phpunit
```

Expected: all existing tests pass.

- [ ] **Step 7: Commit**

```bash
git add views/layout.php views/partials/calendar.php views/partials/event-list.php views/partials/event-rows.php
git commit -m "feat: add offline banner and data-offline-disable attributes to read controls"
```

---

## Task 5: Offline JS block in layout.php

**Files:**
- Modify: `views/layout.php`

- [ ] **Step 1: Add the offline JS block to layout.php**

In `views/layout.php`, add the following script block after the nav-drawer script block (after the closing `</script>` of the nav drawer JS, before `</body>`):

```php
    <script>
    (function () {
        'use strict';
        var QUEUE_KEY = 'gistats_pending';
        var BASE_URL  = '<?= htmlspecialchars($config['base_url']) ?>';

        function getQueue() {
            try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); }
            catch (_) { return []; }
        }
        function saveQueue(q) {
            localStorage.setItem(QUEUE_KEY, JSON.stringify(q));
        }
        function showFlash(msg, type) {
            var area = document.getElementById('flash-area');
            if (!area) return;
            var cls = type === 'error' ? 'flash flash-error' : 'flash flash-success';
            area.innerHTML = '<div class="' + cls + '" id="flash-msg">' + msg + '</div>';
            setTimeout(function () {
                var el = document.getElementById('flash-msg');
                if (el) el.style.display = 'none';
            }, 3000);
        }
        function updatePendingUI() {
            var q         = getQueue();
            var indicator = document.getElementById('pending-indicator');
            var syncBtn   = document.getElementById('sync-now-btn');
            if (indicator) {
                indicator.textContent   = q.length > 0 ? q.length + ' queued' : '';
                indicator.style.display = q.length > 0 ? 'inline' : 'none';
            }
            if (syncBtn) {
                syncBtn.style.display = (q.length > 0 && navigator.onLine) ? 'inline-block' : 'none';
            }
        }
        function setOfflineState(isOffline) {
            var banner = document.getElementById('offline-banner');
            if (banner) banner.style.display = isOffline ? 'block' : 'none';
            document.querySelectorAll('[data-offline-disable]').forEach(function (el) {
                el.classList.toggle('offline-disabled', isOffline);
            });
            updatePendingUI();
        }

        setOfflineState(!navigator.onLine);
        window.addEventListener('offline', function () { setOfflineState(true); });
        window.addEventListener('online',  function () { setOfflineState(false); syncQueue(); });

        document.addEventListener('htmx:sendError', function (e) {
            var form = document.getElementById('entry-form');
            if (!form || e.detail.elt !== form) return;
            var action = form.getAttribute('action') || '';
            if (!/\/entries$/.test(action)) return;
            var fd = new FormData(form);
            try {
                var q = getQueue();
                q.push({
                    occurred_at: fd.get('occurred_at'),
                    duration:    fd.get('duration') || '',
                    stool_type:  fd.get('stool_type'),
                    note:        fd.get('note') || '',
                });
                saveQueue(q);
                updatePendingUI();
                if (typeof resetForm === 'function') resetForm();
            } catch (_) {
                showFlash('Unable to queue entry — storage unavailable.', 'error');
            }
        });

        document.addEventListener('htmx:afterSwap', function () { updatePendingUI(); });

        async function syncQueue() {
            var q = getQueue();
            if (q.length === 0) return;
            var token;
            try {
                var r = await fetch(BASE_URL + '/csrf-token');
                if (!r.ok) throw new Error();
                token = (await r.json()).token;
            } catch (_) {
                showFlash('Sync failed — will retry when reconnected.', 'error');
                return;
            }
            var synced = 0;
            for (var i = 0; i < q.length; i++) {
                var item = q[i];
                try {
                    var res = await fetch(BASE_URL + '/entries', {
                        method:  'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'HX-Request':   'true',
                        },
                        body: new URLSearchParams({
                            csrf_token:  token,
                            occurred_at: item.occurred_at,
                            duration:    item.duration,
                            stool_type:  item.stool_type,
                            note:        item.note,
                        }).toString(),
                    });
                    if (!res.ok) throw new Error();
                    var remaining = getQueue();
                    remaining.shift();
                    saveQueue(remaining);
                    synced++;
                    updatePendingUI();
                } catch (_) {
                    showFlash('Sync failed — will retry when reconnected.', 'error');
                    return;
                }
            }
            showFlash(synced + (synced === 1 ? ' entry' : ' entries') + ' synced.', 'success');
        }

        window.syncQueue = syncQueue;
        updatePendingUI();
    }());
    </script>
```

- [ ] **Step 2: Run PHPUnit to confirm nothing is broken**

```bash
vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 3: Commit**

```bash
git add views/layout.php
git commit -m "feat: add offline JS queue and sync logic"
```

---

## Task 6: Rodney integration tests

**Files:**
- Create: `tests/integration/test-offline-banner.sh`
- Create: `tests/integration/test-offline-queue.sh`
- Create: `tests/integration/test-offline-sync.sh`

These scripts are not wired into CI. Run them manually with a dev server running (`npm run server:start`). Set `GISTATS_URL`, `GISTATS_USER`, `GISTATS_PASS` env vars to match your local setup (defaults: `http://localhost:8000`, `admin`, `secret`).

- [ ] **Step 1: Create the integration test directory**

```bash
mkdir -p tests/integration
```

- [ ] **Step 2: Write test-offline-banner.sh**

Create `tests/integration/test-offline-banner.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-offline-banner ==="

rodney start --local
trap 'rodney stop --local' EXIT

rodney open "$BASE_URL/login"
rodney waitload
rodney input '[name="username"]' "$USERNAME"
rodney input '[name="password"]' "$PASSWORD"
rodney click '[type="submit"]'
rodney waitload

# Fire offline event and wait for DOM update
rodney js "window.dispatchEvent(new Event('offline'))"
rodney sleep 0.3

# Banner must be visible
rodney visible '#offline-banner'
echo "PASS: offline banner is visible"

# At least one read control must carry the disabled class
rodney assert "document.querySelector('[data-offline-disable]').classList.contains('offline-disabled')" "true"
echo "PASS: read controls are disabled"

# Fire online event — banner must hide
rodney js "window.dispatchEvent(new Event('online'))"
rodney sleep 0.3
rodney assert "document.getElementById('offline-banner').style.display" "none"
echo "PASS: banner hides when back online"

echo "ALL PASS"
```

```bash
chmod +x tests/integration/test-offline-banner.sh
```

- [ ] **Step 3: Write test-offline-queue.sh**

Create `tests/integration/test-offline-queue.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-offline-queue ==="

rodney start --local
trap 'rodney stop --local' EXIT

rodney open "$BASE_URL/login"
rodney waitload
rodney input '[name="username"]' "$USERNAME"
rodney input '[name="password"]' "$PASSWORD"
rodney click '[type="submit"]'
rodney waitload

# Clear any pre-existing queue
rodney js "localStorage.removeItem('gistats_pending')"

# Go offline
rodney js "window.dispatchEvent(new Event('offline'))"
rodney sleep 0.2

# Simulate a send error on the entry form (HTMX fires this on network failure)
rodney js "document.dispatchEvent(new CustomEvent('htmx:sendError', { bubbles: true, detail: { elt: document.getElementById('entry-form') } }))"
rodney sleep 0.2

# Queue must contain 1 item
rodney assert "JSON.parse(localStorage.getItem('gistats_pending') || '[]').length" "1"
echo "PASS: entry queued in localStorage"

# Pending indicator must show count
rodney assert "document.getElementById('pending-indicator').textContent" "1 queued"
echo "PASS: pending indicator shows count"

echo "ALL PASS"
```

```bash
chmod +x tests/integration/test-offline-queue.sh
```

- [ ] **Step 4: Write test-offline-sync.sh**

Create `tests/integration/test-offline-sync.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-offline-sync ==="

rodney start --local
trap 'rodney stop --local' EXIT

rodney open "$BASE_URL/login"
rodney waitload
rodney input '[name="username"]' "$USERNAME"
rodney input '[name="password"]' "$PASSWORD"
rodney click '[type="submit"]'
rodney waitload

# Pre-populate queue with one valid entry
NOW=$(date '+%Y-%m-%dT%H:%M')
rodney js "localStorage.setItem('gistats_pending', JSON.stringify([{ occurred_at: '$NOW', duration: '05:00', stool_type: '4', note: 'integration test' }]))"

# Fire online event to trigger auto-sync
rodney js "window.dispatchEvent(new Event('online'))"
rodney sleep 2

# Queue must be empty after sync
rodney assert "JSON.parse(localStorage.getItem('gistats_pending') || '[]').length" "0"
echo "PASS: queue drained after sync"

# Flash message must be present
rodney assert "document.getElementById('flash-msg') !== null" "true"
echo "PASS: sync flash message appeared"

echo "ALL PASS"
```

```bash
chmod +x tests/integration/test-offline-sync.sh
```

- [ ] **Step 5: Run PHPUnit one final time to confirm all backend tests pass**

```bash
vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add tests/integration/
git commit -m "test: add rodney integration tests for offline queue behavior"
```
