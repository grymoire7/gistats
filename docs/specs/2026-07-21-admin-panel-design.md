# Admin Panel & Database Lifecycle Design

**Date:** 2026-07-21
**Context:** `gistats` is a single-user, password-protected personal GI health tracker (see `docs/specs/2026-05-28-deploy-strategy-design.md` for the DreamHost deploy pipeline this interacts with).

---

## Problem

Standing up and operating this app currently requires separate out-of-band tooling: `seed.php` (interactive CLI, creates the db + first user), and a set of proposed database management scripts (fresh install, backup, restore) that would live outside the app. For a single-user app that others might also self-host, this is more ceremony than necessary. This design folds all of that into the app itself — no separate scripts, no CLI tooling beyond a documented last-resort break-glass procedure.

---

## First-boot account setup

**Bootstrap sequence**, run at the top of `index.php` before the router dispatches, on every request that isn't already authenticated:

1. Ensure the configured `db_path`'s parent directory exists (`mkdir($dirname, 0755, true)` if missing). `db_path` comes from `config.php`/`config.local.php`, never from user input, so there's no path-traversal concern in creating it automatically. This retires the manual `mkdir -p ~/data/gitstats` one-time server-setup step.
2. `DB::init($config)` — connects via PDO; SQLite auto-creates the file if the directory exists but the file doesn't.
3. `DB::createSchema()` — already idempotent (`CREATE TABLE IF NOT EXISTS`), safe to call unconditionally on every request.
4. Check `SELECT COUNT(*) FROM users`. If zero and the requested path isn't already `/setup`, redirect there.

**`GET /setup`** — renders a "Create your account" form (username, password, confirm password). If a user already exists, redirects home instead (setup can't be re-run once an account exists).

**`POST /setup`** — validates input, calls a new `create_account($username, $password)` in `lib/auth.php` (inserts the user with `password_hash(..., PASSWORD_BCRYPT)`, same hashing `seed.php` used), logs the new user in via the existing `login()`, redirects to `/`.

**Retired:** `seed.php` is deleted. Its schema-creation + user-insert logic moves into `/setup`'s POST handler, triggered by a web form instead of a CLI prompt. Local dev setup simplifies to: `composer install && npm install && npm run build:css`, then load the app in a browser and fill out the form — no `php seed.php` step.

---

## Admin panel

**Nav drawer change:** the "Export CSV" and "Import CSV" links are replaced by a single "Admin" link (shown only when logged in, same position in `views/layout.php`).

**`GET /admin`** (`views/admin.php`, `require_auth()`) — a single hub page containing:
- Change Password form
- Import CSV form (markup moved in from the current `views/import.php`, which is deleted)
- Export CSV — a plain `<a href="/export">` link; `/export` has no view of its own today and needs no route change
- Backup Database — a link to `/admin/backup`
- Restore from Backup — an upload form posting to `/admin/restore`

**`GET /import`** route is removed (no standalone import page anymore). **`POST /import`** keeps its existing handler logic and URL unchanged — only its `render('import', [...])` call becomes `render('admin', [...])`, so results/errors surface inside the combined Admin page. This matches the app's existing no-redirect-after-POST pattern (e.g. `POST /login` already re-renders `login` in place on failure).

**`POST /admin/password`** — requires the current password (verified via `password_verify()`, same check `login()` uses) plus a new password and confirmation, before updating `password_hash`. Chosen deliberately over a no-current-password-required flow: cheap protection against an unlocked device/left-open session silently locking the real owner out.

All four routes (`/admin`, `/admin/password`, `/admin/backup`, `/admin/restore`) are gated by the same `require_auth()` used everywhere else — no separate admin-role concept, since there's exactly one account.

---

## Backup

**`GET /admin/backup`**, `require_auth()`:

1. Build a unique path in `sys_get_temp_dir()`, e.g. `gistats_backup_<random hex>.sqlite`. Deliberately *not* built with `tempnam()`, since that pre-creates an empty file at the path and SQLite's `VACUUM INTO` errors if its target already exists. The random component only needs to guarantee no collision between concurrent requests — this file is never shown to the user.
2. Run `VACUUM INTO` against that path. This is SQLite's own built-in consistent-snapshot mechanism — safe against concurrent writers and WAL without needing to shell out to the `sqlite3` CLI binary (not guaranteed to be installed on DreamHost). Note: `VACUUM INTO '<path>'` takes its target as a SQL string literal, not a bindable parameter — PDO's `?`/named-parameter binding doesn't apply here the way it does for `DB::query()`'s other calls. Since the path is entirely server-generated (a random hex string, never user input), this is safe, but the query needs to be built with the path embedded directly (single-quoted, with any embedded `'` doubled per SQLite string-literal escaping — moot in practice since the generated path only ever contains a fixed prefix, hex digits, and a fixed suffix, but worth being explicit about in the implementation).
3. Stream it back: `Content-Type: application/octet-stream`, `Content-Disposition: attachment; filename="gistats-backup-<YmdHis>.sqlite"` — full timestamp down to the second, so multiple same-day backups don't collide or get silently renamed by the browser.
4. Clean up via `register_shutdown_function('unlink', $tempPath)` rather than a plain post-`readfile()` `unlink()`, so cleanup still runs even if the client disconnects mid-download (PHP by default aborts the script on client disconnect, which would skip a simple sequential call placed after `readfile()`).

---

## Restore

**`POST /admin/restore`**, `require_auth()`, `require_csrf()`, multipart upload. The form includes a required "I understand this will overwrite my current data" checkbox, enforced client-side (same disabled-until-valid pattern the import form already uses for its file input).

**Validation** (reject with a clear error and leave the live db untouched if any check fails):
1. Standard `$_FILES` upload-error handling, same pattern as `/import`.
2. Read the first 16 bytes of the uploaded file and confirm they match SQLite's file-format magic header (`"SQLite format 3\0"`).
3. Open the uploaded tmp file with a throwaway PDO connection and confirm a `users` table exists (`SELECT COUNT(*) FROM users`) — catches a file that merely has a valid SQLite header but isn't this app's schema.

**Atomic swap** — order matters here and was deliberately chosen to avoid a real corruption risk:

1. `DB::reset()` — drop this request's own PDO handle on the *old* database (already-existing method in `lib/db.php`, currently only used by tests).
2. Delete the *old* `$dbPath . '-wal'` and `$dbPath . '-shm'` if present. Safe to discard unconditionally — we're about to replace the whole database, so there's nothing in the old WAL worth preserving.
3. Copy the validated upload into a temp path in the *same directory* as the live `db_path` (not `sys_get_temp_dir()` — that could be a different filesystem, which would make the next step non-atomic).
4. `rename()` the temp path over the live `db_path` — atomic within one filesystem.

This ordering (clear stale sidecar files *before* the new main file lands, not after) matters because SQLite's WAL validity checking is not verified to detect "the main database file was completely replaced by an external process while an old WAL sat next to it" — the salt/checksum mechanism in the WAL header is a self-consistency check on the WAL file, not a guaranteed cross-check against the current main file's actual content. Rather than rely on undocumented behavior in an edge case, the design guarantees there is never a moment where a main database file and an unrelated `-wal`/`-shm` coexist on disk. (A `VACUUM INTO` backup is always a complete, fully-checkpointed standalone file with no WAL of its own, so a valid restore upload should never come bundled with its own sidecar files either.)

**After a successful swap:** call `logout()` unconditionally and redirect to `/login`. The restored `users` table may not contain the currently logged-in account at all (different id, different password hash, or a backup taken before the account existed) — rather than try to determine if the session is still valid, just end it.

---

## Password recovery (no new code)

If the password is completely forgotten (not just "want to change it," but "can't produce the current one"), recovery is a documented SSH break-glass procedure in `README.md` — not a new app feature, not a stored recovery secret. A stored recovery key/secret was considered and rejected: if it's lost along with the password, the only way out is a full reset that loses all data, which is a worse failure mode than requiring SSH access (the same trust boundary the server setup already depends on).

```bash
cd ~/magicbydesign.com/gistats
php -r "
require 'config.php';
require 'lib/db.php';
\$config = require 'config.php';
DB::init(\$config);
DB::execute('UPDATE users SET password_hash = ? WHERE username = ?', [password_hash('newpassword', PASSWORD_BCRYPT), 'yourusername']);
echo \"Password updated.\n\";
"
```

Uses only the app's own `config.php`/`lib/db.php` and PHP's CLI — no dependency on the `sqlite3` binary being installed, and no separate script file to maintain.

---

## Testing

Following this codebase's existing convention (`ExportTest.php`, `ConfigTest.php`): unit-test `lib/` functions directly against real temp SQLite fixtures, no mocks; leave HTTP-route wiring to the `tests/integration/` (`rodney`) suite.

- **`lib/auth.php` additions:** `create_account()`, `change_password()` — tested the way `AuthTest.php` already tests `login()`.
- **New `lib/backup.php`:** a function wrapping the `VACUUM INTO` logic (assert the output file opens cleanly and its data matches the source), and a function wrapping the restore validate-and-swap sequence (assert: rejects a non-SQLite file, rejects a SQLite file missing a `users` table, correctly swaps content, correctly removes stale `-wal`/`-shm`).
- **`lib/db.php` additions:** directory auto-creation, and the user-count check that drives the `/setup` redirect.
- **Integration tests:** first-boot setup end-to-end, and the Admin page's password-change, backup-download, and restore-from-upload flows, using the existing isolated-server-plus-temp-db pattern.

---

## File changes

- **New:** `lib/backup.php`, `views/setup.php`, `views/admin.php`
- **Modify:** `lib/auth.php` (`create_account()`, `change_password()`), `lib/db.php` (directory auto-creation, user-count check), `router.php`/`index.php` (bootstrap sequence, new routes), `views/layout.php` (nav drawer), `README.md` (see below)
- **Delete:** `seed.php`, `views/import.php`

---

## Interaction with the existing deploy pipeline

`docs/specs/2026-05-28-deploy-strategy-design.md` and its implementation (currently on branch `feature/deploy-strategy`, PR #1, not yet merged) documented a one-time production setup that this design makes obsolete:

- The manual `mkdir -p ~/data/gitstats` SSH step is no longer needed — the app now creates its data directory itself.
- The manual `cd ~/magicbydesign.com/gistats && php seed.php` step is no longer possible — `seed.php` no longer exists. First-time production setup becomes: deploy, then visit the site and fill out the `/setup` form.
- `README.md`'s "Deploying with GitHub Actions" section (added by that plan) needs its one-time-setup instructions rewritten to match, and gains the password-recovery break-glass procedure from this design.
- Since `seed.php` is deleted from the repo entirely, the deploy pipeline's rsync no longer has anything to exclude or include regarding that file — the "ship `seed.php`" deviation from the deploy plan becomes moot rather than needing to be re-decided.

This plan's implementation should account for whichever of `main` / `feature/deploy-strategy` is current at execution time, and update the README section that plan already wrote rather than duplicating it.
