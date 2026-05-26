# Offline Entry Queue — Design Spec
**Date:** 2026-05-26

## Overview

Allow a user to log an entry when there is no network connection. Writes are
queued in `localStorage` and replayed automatically (or manually) when
connectivity is restored. Read operations degrade gracefully — the user is
informed they are offline and dynamic read controls are disabled.

No service worker is introduced. All logic is vanilla JS (~75 lines) in an
inline script block in `layout.php`.

---

## Scope

**In scope:**
- Queuing `POST /entries` when offline.
- Auto-syncing the queue when the `online` event fires.
- Manual "Sync now" fallback button.
- Offline banner and read-control dimming.
- `GET /csrf-token` PHP endpoint for fresh tokens at sync time.
- PHPUnit tests for the new endpoint.
- Rodney bash integration tests for the frontend behavior.

**Out of scope:**
- Caching pages or static assets for offline reads (no service worker).
- Offline support for edit, copy, or delete operations.
- Frontend JS test framework.

---

## Architecture

Three concerns handled independently by a single inline script block added to `layout.php`:

### 1. Connectivity UI

`online` / `offline` window events toggle:
- An **offline banner** (shown when offline, hidden when online).
- A `.offline-disabled` CSS class on read-only HTMX controls.

Initial state is derived from `navigator.onLine` on page load.

### 2. Write Queue

`htmx:sendError` on the entry form triggers queuing:
- Serialize the form's `FormData` to a plain object: `{ occurred_at, duration, stool_type, note }`.
- Append to the `gistats_pending` array in `localStorage`.
- Update the pending indicator.
- Reset the form (same behavior as a successful save).

The CSRF token is **not** stored in the queue — a fresh token is fetched at sync time.

### 3. Sync

Triggered by the `online` event (auto) or the "Sync now" button (manual):
1. `GET /csrf-token` → fresh token.
2. For each item in `gistats_pending` (in order): `fetch POST /entries` with payload + fresh token.
3. On 200: remove item from queue, update pending indicator.
4. On failure: stop replay, leave remaining items in queue, show flash "Sync failed — will retry when reconnected."
5. On full drain: hide pending indicator and sync button, show flash "N entries synced."

---

## CSRF Token Endpoint

```
GET /csrf-token
```

- Requires authentication; returns 401 if unauthenticated.
- Returns `Content-Type: application/json` with body `{"token": "<value>"}`.
- Token is generated via the existing `csrf.php` helper (same token stored in session).
- Four lines of PHP added to the router in `index.php`.

Rationale: storing the token with the queued payload risks a 403 on replay if
the session expires during a long offline window (e.g., airplane mode
overnight). Fetching a fresh token at sync time makes replay robust regardless
of offline duration.

---

## UI Elements

All additions fit within the existing dark-mode palette and mobile-first
layout. No new view files.

| Element                        | Location                                         | Visibility               |
| ------------------------------ | ------------------------------------------------ | ------------------------ |
| Offline banner                 | `layout.php`, below flash area                   | Offline only             |
| `.offline-disabled` class      | Calendar nav, day-filter links, load-more button | Offline only             |
| Pending indicator ("N queued") | `entry-form.php`, near Save button               | When queue non-empty     |
| "Sync now" button              | `entry-form.php`, below pending indicator        | Online + queue non-empty |

**Offline banner** uses existing muted palette: `rgba(255,255,255,0.55)` text on `#2a2a2a` background with `#3a3a3a` border-bottom.

**`.offline-disabled`**: `opacity: 0.4; pointer-events: none;` — toggled via JS on elements marked with `data-offline-disable` attribute.

---

## Data Flow

**Happy path (online) — unchanged:**
User submits form → HTMX `POST /entries` → 200 → flash + blank form.

**Offline write path:**
1. `offline` event → banner shown, read controls dimmed.
2. User submits entry form → HTMX attempts `POST /entries` → `htmx:sendError`.
3. JS serializes `FormData`, appends to `gistats_pending`, resets form.
4. Pending indicator shows "1 queued". No error shown to user.

**Sync path:**
1. `online` event fires (or "Sync now" tapped).
2. `GET /csrf-token` → fresh token.
3. Sequential `fetch POST /entries` for each queued item.
4. Success: item removed from queue, indicator updated.
5. Failure: replay halts, remaining items preserved, flash shown.
6. Full drain: indicator and sync button hidden, flash "N entries synced."

---

## File Changes

| File                                       | Change                                                  |
| ------------------------------------------ | ------------------------------------------------------- |
| `index.php`                                | Add `GET /csrf-token` route (4 lines)                   |
| `views/layout.php`                         | Add offline banner markup + inline JS block (~75 lines) |
| `views/partials/entry-form.php`            | Add pending indicator + sync button                     |
| `css/input.css`                            | Add `.offline-disabled` utility class                   |
| `tests/OfflineCsrfTokenTest.php`           | PHPUnit tests for new endpoint                          |
| `tests/integration/test-offline-banner.sh` | Rodney integration test                                 |
| `tests/integration/test-offline-queue.sh`  | Rodney integration test                                 |
| `tests/integration/test-offline-sync.sh`   | Rodney integration test                                 |

---

## Testing

### Backend (PHPUnit)

- `GET /csrf-token` returns 200 with `{"token": "..."}` when authenticated.
- `GET /csrf-token` returns 401 when unauthenticated.
- Existing `POST /entries` tests are unchanged.

### Frontend (rodney bash scripts in `tests/integration/`)

Scripts are not wired into CI — they serve as runnable documentation of expected behavior.

- **`test-offline-banner.sh`** — fires synthetic `offline` event via `rodney js`, asserts banner is visible and read controls have `.offline-disabled`.
- **`test-offline-queue.sh`** — fires `offline` event, submits the entry form, asserts `localStorage.getItem('gistats_pending')` contains one queued item and pending indicator shows "1 queued".
- **`test-offline-sync.sh`** — pre-populates `gistats_pending` via `rodney js`, fires `online` event, waits for DOM to stabilize, asserts queue is empty and sync flash appears.

---

## Error Handling

- `GET /csrf-token` failure during sync: show flash "Sync failed — will retry when reconnected." Queue preserved.
- `POST /entries` non-200 response during sync: stop replay at that item, preserve remaining queue, show flash.
- `localStorage` unavailable (private browsing with storage blocked): catch the `setItem` exception, show flash "Unable to queue entry — storage unavailable."

