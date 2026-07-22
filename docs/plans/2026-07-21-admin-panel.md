# Admin Panel & Database Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fold first-boot account setup, an Admin panel (password change, import/export, backup, restore), and a documented SSH break-glass password-recovery procedure into the app itself, retiring `seed.php` and the need for any separate database-management scripts.

**Architecture:** A bootstrap sequence at the top of `index.php` ensures the db directory/file/schema exist on every request, then redirects to `/setup` if the `users` table is empty. `/setup` is a one-time account-creation form. A new `/admin` hub page (`views/admin.php`) consolidates Change Password, Import/Export, Backup, and Restore — replacing the "Export CSV"/"Import CSV" nav-drawer links with a single "Admin" link. Backup uses SQLite's own `VACUUM INTO`; restore validates an upload then does an atomic file swap with careful WAL/SHM cleanup ordering.

**Tech Stack:** PHP 8+, PDO/SQLite, PHPUnit, vanilla JS (matching the existing import-form pattern), Tailwind-compiled CSS.

## Global Constraints

- TDD red-green-refactor for all development (write failing test → minimal implementation → pass) — from project `CLAUDE.md`.
- Conventional commit format for all commits — from user's global `CLAUDE.md`.
- Follow this codebase's existing test convention: unit-test `lib/` functions directly against real temp SQLite fixtures (`sys_get_temp_dir()`), no mocks. Do **not** add `tests/integration/` (`rodney`) coverage for any of this plan's flows — matching existing precedent, where form-based, non-realtime features (e.g. CSV import today) have PHPUnit coverage only; `rodney` is reserved for genuinely JS/HTMX-dependent behavior (calendar OOB swaps, offline queueing, timers). The new restore form's confirm-checkbox/file-input JS is the same complexity class as the existing (untested-by-rodney) import file-input JS.
- `db_path` is never taken from user input — it stays a config-level decision (`config.php` default, `config.local.php`, or `GISTATS_DB_PATH` env var). No form in this plan ever asks for a filesystem path.
- Single-user app: no separate "admin role" — every new route is gated by the existing `require_auth()`, identical to every other authenticated route.
- Password change requires the current password (verified via `password_verify()`, same check `login()` already uses).
- Password recovery (fully forgotten password) is **documentation only** — a break-glass SSH procedure in `README.md`, not a stored recovery secret or new app feature. Deliberate choice: a stored recovery key that's also lost forces a full data-losing reset, which is worse than requiring SSH access (the same trust boundary server setup already depends on).
- Backup is on-demand download only — no server-side retention, no cron.
- Restore's atomic-swap order is load-bearing and must not be reordered: (1) drop this request's own DB connection, (2) delete stale `-wal`/`-shm` sidecar files for the *old* database, (3) copy the validated upload into a temp path in the *same directory* as the live db, (4) `rename()` it into place. Deleting stale sidecar files *before* the new main file lands (not after) avoids a window where an unrelated `-wal` file could sit next to a database it wasn't created for — SQLite's WAL validity checking is not verified to reliably reject that scenario.
- Restore forces `logout()` unconditionally afterward and redirects to `/login` — the restored `users` table may not contain the current session's account at all.
- **Branch:** this plan continues on `feature/deploy-strategy` (already holds the not-yet-merged deploy pipeline and this plan's own spec, commit `a94454c`) — not a new branch off `main`. Task 6's README edits apply to the "Deploying with GitHub Actions" section that branch already added.
- Full spec: `docs/specs/2026-07-21-admin-panel-design.md`.

---

### Task 1: First-boot account setup

**Files:**
- Modify: `lib/db.php` (`DB::init()`)
- Modify: `lib/auth.php`
- Modify: `index.php` (bootstrap sequence, `/setup` routes)
- Create: `views/setup.php`
- Test: `tests/DBTest.php`, `tests/SetupTest.php` (new)
- Delete: `seed.php`

**Interfaces:**
- Produces: `DB::init()` auto-creates the `db_path`'s parent directory if missing. `no_users_exist(): bool` and `create_account(string $username, string $password): void` in `lib/auth.php`, used by `index.php`'s bootstrap and the `/setup` POST handler.

- [ ] **Step 1: Write the failing test for directory auto-creation**

Add to `tests/DBTest.php` (inside the `DBTest` class):

```php
    public function testInitCreatesMissingParentDirectory(): void
    {
        $nestedDir    = sys_get_temp_dir() . '/gistats_test_nested_' . uniqid();
        $nestedDbPath = $nestedDir . '/database.sqlite';
        $pdo = DB::init(['db_path' => $nestedDbPath]);
        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertDirectoryExists($nestedDir);
        unlink($nestedDbPath);
        rmdir($nestedDir);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/DBTest.php`
Expected: FAIL on `testInitCreatesMissingParentDirectory` — `$nestedDir` doesn't exist and `DB::init()` doesn't create it yet, so the `PDO` constructor throws.

- [ ] **Step 3: Implement directory auto-creation in `DB::init()`**

In `lib/db.php`, replace:

```php
    public static function init(array $config): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . $config['db_path']);
```

with:

```php
    public static function init(array $config): PDO
    {
        if (self::$pdo === null) {
            $dbDir = dirname($config['db_path']);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }
            self::$pdo = new PDO('sqlite:' . $config['db_path']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/DBTest.php`
Expected: PASS

- [ ] **Step 5: Write the failing tests for `no_users_exist()` and `create_account()`**

Create `tests/SetupTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

class SetupTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_setup_test_' . uniqid() . '.sqlite';
        DB::reset();
        DB::init(['db_path' => $this->dbPath]);
        DB::createSchema();
    }

    protected function tearDown(): void
    {
        DB::reset();
        if (file_exists($this->dbPath)) unlink($this->dbPath);
    }

    public function testNoUsersExistOnFreshDatabase(): void
    {
        $this->assertTrue(no_users_exist());
    }

    public function testNoUsersExistFalseAfterAccountCreated(): void
    {
        create_account('admin', 'secret123');
        $this->assertFalse(no_users_exist());
    }

    public function testCreateAccountHashesPassword(): void
    {
        create_account('admin', 'secret123');
        $user = DB::fetch('SELECT * FROM users WHERE username = ?', ['admin']);
        $this->assertNotNull($user);
        $this->assertTrue(password_verify('secret123', $user['password_hash']));
    }

    public function testCreateAccountAllowsLoginAfterward(): void
    {
        create_account('admin', 'secret123');
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $result = login('admin', 'secret123');
        $this->assertTrue($result);
        $_SESSION = [];
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/SetupTest.php`
Expected: FAIL — `no_users_exist()` and `create_account()` don't exist yet.

- [ ] **Step 7: Implement `no_users_exist()` and `create_account()`**

Add to `lib/auth.php` (after the existing `logout()` function):

```php
function no_users_exist(): bool
{
    $result = DB::fetch('SELECT COUNT(*) AS count FROM users');
    return ((int) $result['count']) === 0;
}

function create_account(string $username, string $password): void
{
    DB::execute(
        'INSERT INTO users (username, password_hash) VALUES (?, ?)',
        [$username, password_hash($password, PASSWORD_BCRYPT)]
    );
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/SetupTest.php`
Expected: PASS

- [ ] **Step 9: Wire the bootstrap sequence and `/setup` routes into `index.php`**

Replace:

```php
try {
    DB::init($config);
} catch (PDOException $e) {
    http_response_code(503);
    echo '<h1>Database unavailable</h1><p>Run <code>php seed.php</code> to set up the database.</p>';
    exit;
}
```

with:

```php
try {
    DB::init($config);
    DB::createSchema();
} catch (PDOException $e) {
    http_response_code(503);
    echo '<h1>Database unavailable</h1><p>Check that the configured database path is writable.</p>';
    exit;
}

$requestPath = strtok($_SERVER['REQUEST_URI'], '?');
if ($requestPath !== '/') {
    $requestPath = rtrim($requestPath, '/');
}
if ($requestPath !== '/setup' && no_users_exist()) {
    header('Location: /setup');
    exit;
}
```

Then insert these two routes right after the `$router->post('/login', ...)` block (before `$router->post('/logout', ...)`):

```php
$router->get('/setup', function () use ($config) {
    if (!no_users_exist()) { header('Location: /'); exit; }
    render('setup');
});

$router->post('/setup', function () use ($config) {
    require_csrf();
    if (!no_users_exist()) { header('Location: /'); exit; }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    $errors = [];
    if ($username === '') { $errors[] = 'Username is required.'; }
    if (strlen($password) < 8) { $errors[] = 'Password must be at least 8 characters.'; }
    if ($password !== $confirm) { $errors[] = 'Passwords do not match.'; }

    if ($errors) {
        render('setup', ['errors' => $errors]);
        return;
    }

    create_account($username, $password);
    login($username, $password);
    header('Location: /');
    exit;
});
```

- [ ] **Step 10: Create the setup view**

Create `views/setup.php`:

```php
<?php
$errors = $errors ?? [];
?>
<div class="card" style="max-width:360px;margin:60px auto;">
    <h2 style="margin:0 0 20px;font-size:20px;font-weight:600;">Create your account</h2>
    <?php if ($errors): ?>
    <ul style="color:var(--color-red);margin:0 0 16px;padding-left:18px;font-size:14px;">
        <?php foreach ($errors as $error): ?>
        <li><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/setup" style="display:flex;flex-direction:column;gap:14px;">
        <?= csrf_field() ?>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Username</label>
            <input class="form-input" type="text" name="username" required autocomplete="username">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Password</label>
            <input class="form-input" type="password" name="password" required autocomplete="new-password" minlength="8">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Confirm password</label>
            <input class="form-input" type="password" name="password_confirm" required autocomplete="new-password" minlength="8">
        </div>
        <button type="submit" class="btn-primary" style="align-self:flex-start;">Create account</button>
    </form>
</div>
```

- [ ] **Step 11: Delete `seed.php`**

```bash
git rm seed.php
```

- [ ] **Step 12: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 13: Manually verify in a browser**

```bash
rm -f database.sqlite
pitchfork start
```

Visit `http://localhost:8000` — confirm the "Create your account" form appears, submitting it logs you in and lands on the home page, and reloading `/setup` afterward redirects to `/` instead of showing the form again.

- [ ] **Step 14: Commit**

```bash
git add lib/db.php lib/auth.php index.php views/setup.php tests/DBTest.php tests/SetupTest.php
git commit -m "feat: add first-boot account setup, retire seed.php"
```

---

### Task 2: Admin hub page + password change

**Files:**
- Modify: `lib/auth.php`, `index.php`, `views/layout.php`
- Create: `views/admin.php`
- Test: `tests/AuthTest.php`

**Interfaces:**
- Consumes: `require_auth()`, `require_csrf()`, `csrf_field()`, `current_user_id()` (existing).
- Produces: `change_password(int $userId, string $currentPassword, string $newPassword): bool` in `lib/auth.php`. `GET /admin` and `POST /admin/password` routes. `views/admin.php` renders with optional `$passwordError` (string) / `$passwordSuccess` (bool) variables — later tasks add more sections and variables to this same view.

- [ ] **Step 1: Write the failing tests**

Add to `tests/AuthTest.php` (inside the `AuthTest` class):

```php
    public function testChangePasswordWithCorrectCurrentPassword(): void
    {
        login('admin', 'secret');
        $userId = current_user_id();
        $result = change_password($userId, 'secret', 'newpassword123');
        $this->assertTrue($result);
        $_SESSION = [];
        $this->assertTrue(login('admin', 'newpassword123'));
    }

    public function testChangePasswordWithWrongCurrentPassword(): void
    {
        login('admin', 'secret');
        $userId = current_user_id();
        $result = change_password($userId, 'wrongpassword', 'newpassword123');
        $this->assertFalse($result);
        $_SESSION = [];
        $this->assertTrue(login('admin', 'secret'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/AuthTest.php`
Expected: FAIL — `change_password()` doesn't exist yet.

- [ ] **Step 3: Implement `change_password()`**

Add to `lib/auth.php` (after `create_account()`):

```php
function change_password(int $userId, string $currentPassword, string $newPassword): bool
{
    $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        return false;
    }
    DB::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($newPassword, PASSWORD_BCRYPT), $userId]);
    return true;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/AuthTest.php`
Expected: PASS

- [ ] **Step 5: Add the `/admin` and `/admin/password` routes**

Insert into `index.php`, right before the `$router->get('/export', ...)` block:

```php
$router->get('/admin', function () use ($config) {
    require_auth();
    render('admin');
});

$router->post('/admin/password', function () use ($config) {
    require_auth();
    require_csrf();
    $userId  = current_user_id();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['new_password_confirm'] ?? '';

    if (strlen($new) < 8) {
        render('admin', ['passwordError' => 'New password must be at least 8 characters.']);
        return;
    }
    if ($new !== $confirm) {
        render('admin', ['passwordError' => 'New passwords do not match.']);
        return;
    }
    if (!change_password($userId, $current, $new)) {
        render('admin', ['passwordError' => 'Current password is incorrect.']);
        return;
    }
    render('admin', ['passwordSuccess' => true]);
});
```

- [ ] **Step 6: Create the admin view**

Create `views/admin.php`:

```php
<?php
$passwordError   = $passwordError   ?? null;
$passwordSuccess = $passwordSuccess ?? null;
?>
<h2 style="font-size:18px;font-weight:600;margin:0 0 20px;">Admin</h2>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Change Password</h3>
    <?php if ($passwordError): ?>
    <p style="color:var(--color-red);margin:0 0 16px;font-size:14px;"><?= htmlspecialchars($passwordError) ?></p>
    <?php endif; ?>
    <?php if ($passwordSuccess): ?>
    <p style="color:var(--color-green);margin:0 0 16px;font-size:14px;">Password updated.</p>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/admin/password" style="display:flex;flex-direction:column;gap:14px;">
        <?= csrf_field() ?>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Current password</label>
            <input class="form-input" type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">New password</label>
            <input class="form-input" type="password" name="new_password" required autocomplete="new-password" minlength="8">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Confirm new password</label>
            <input class="form-input" type="password" name="new_password_confirm" required autocomplete="new-password" minlength="8">
        </div>
        <button type="submit" class="btn-primary" style="align-self:flex-start;">Update Password</button>
    </form>
</div>
```

- [ ] **Step 7: Add the "Admin" nav link**

In `views/layout.php`, replace:

```php
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/stats" style="color:var(--color-text);text-decoration:none;font-size:15px;">Statistics</a></li>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/export" style="color:var(--color-text);text-decoration:none;font-size:15px;">Export CSV</a></li>
```

with:

```php
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/stats" style="color:var(--color-text);text-decoration:none;font-size:15px;">Statistics</a></li>
            <?php if (is_logged_in()): ?>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/admin" style="color:var(--color-text);text-decoration:none;font-size:15px;">Admin</a></li>
            <?php endif; ?>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/export" style="color:var(--color-text);text-decoration:none;font-size:15px;">Export CSV</a></li>
```

(Export/Import links stay put for now — Task 3 relocates them.)

- [ ] **Step 8: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 9: Manually verify in a browser**

```bash
pitchfork start
```

Log in, open the nav drawer, confirm "Admin" appears, visit it, and confirm changing the password with the wrong current password shows an error while the correct one succeeds and lets you log in with the new password afterward.

- [ ] **Step 10: Commit**

```bash
git add lib/auth.php index.php views/admin.php views/layout.php tests/AuthTest.php
git commit -m "feat: add admin page with password change"
```

---

### Task 3: Move Import/Export into the Admin page

**Files:**
- Modify: `index.php`, `views/admin.php`, `views/layout.php`
- Delete: `views/import.php`

**Interfaces:**
- Consumes: existing `import_csv()` (`lib/entries.php`), unchanged.
- Produces: `views/admin.php` gains `$importResult`/`$importError` variables (mirroring the deleted `views/import.php`'s `$result`/`$error`).

- [ ] **Step 1: Remove the standalone `/import` page route and repoint the POST handler**

In `index.php`, delete this block entirely:

```php
$router->get('/import', function () use ($config) {
    require_auth();
    render('import');
});

```

Then replace the two `render('import', ...)` calls inside `$router->post('/import', ...)`:

```php
    $uploadError = $_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        render('import', ['error' => 'The uploaded file exceeds the maximum allowed size.']);
        return;
    }
    if (empty($_FILES['csv_file']['tmp_name']) || $uploadError !== UPLOAD_ERR_OK) {
        render('import', ['error' => 'No file uploaded or upload error.']);
        return;
    }

    $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
    if ($csvContent === false || trim($csvContent) === '') {
        render('import', ['error' => 'The uploaded file is empty.']);
        return;
    }

    $result = import_csv(current_user_id(), $csvContent, $tz);
    render('import', ['result' => $result]);
```

with:

```php
    $uploadError = $_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        render('admin', ['importError' => 'The uploaded file exceeds the maximum allowed size.']);
        return;
    }
    if (empty($_FILES['csv_file']['tmp_name']) || $uploadError !== UPLOAD_ERR_OK) {
        render('admin', ['importError' => 'No file uploaded or upload error.']);
        return;
    }

    $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
    if ($csvContent === false || trim($csvContent) === '') {
        render('admin', ['importError' => 'The uploaded file is empty.']);
        return;
    }

    $result = import_csv(current_user_id(), $csvContent, $tz);
    render('admin', ['importResult' => $result]);
```

- [ ] **Step 2: Delete the standalone import view**

```bash
git rm views/import.php
```

- [ ] **Step 3: Fold the import form and an export link into the admin view**

In `views/admin.php`, replace:

```php
<?php
$passwordError   = $passwordError   ?? null;
$passwordSuccess = $passwordSuccess ?? null;
?>
<h2 style="font-size:18px;font-weight:600;margin:0 0 20px;">Admin</h2>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Change Password</h3>
    <?php if ($passwordError): ?>
    <p style="color:var(--color-red);margin:0 0 16px;font-size:14px;"><?= htmlspecialchars($passwordError) ?></p>
    <?php endif; ?>
    <?php if ($passwordSuccess): ?>
    <p style="color:var(--color-green);margin:0 0 16px;font-size:14px;">Password updated.</p>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/admin/password" style="display:flex;flex-direction:column;gap:14px;">
        <?= csrf_field() ?>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Current password</label>
            <input class="form-input" type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">New password</label>
            <input class="form-input" type="password" name="new_password" required autocomplete="new-password" minlength="8">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Confirm new password</label>
            <input class="form-input" type="password" name="new_password_confirm" required autocomplete="new-password" minlength="8">
        </div>
        <button type="submit" class="btn-primary" style="align-self:flex-start;">Update Password</button>
    </form>
</div>
```

with:

```php
<?php
$passwordError   = $passwordError   ?? null;
$passwordSuccess = $passwordSuccess ?? null;
$importResult    = $importResult    ?? null;
$importError     = $importError     ?? null;
?>
<h2 style="font-size:18px;font-weight:600;margin:0 0 20px;">Admin</h2>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Change Password</h3>
    <?php if ($passwordError): ?>
    <p style="color:var(--color-red);margin:0 0 16px;font-size:14px;"><?= htmlspecialchars($passwordError) ?></p>
    <?php endif; ?>
    <?php if ($passwordSuccess): ?>
    <p style="color:var(--color-green);margin:0 0 16px;font-size:14px;">Password updated.</p>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/admin/password" style="display:flex;flex-direction:column;gap:14px;">
        <?= csrf_field() ?>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Current password</label>
            <input class="form-input" type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">New password</label>
            <input class="form-input" type="password" name="new_password" required autocomplete="new-password" minlength="8">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Confirm new password</label>
            <input class="form-input" type="password" name="new_password_confirm" required autocomplete="new-password" minlength="8">
        </div>
        <button type="submit" class="btn-primary" style="align-self:flex-start;">Update Password</button>
    </form>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Import / Export</h3>

    <?php if ($importResult !== null): ?>
    <div style="margin-bottom:16px;padding:12px 16px;background:var(--color-canvas);border:1px solid var(--color-border);border-radius:8px;">
        <p style="margin:0 0 4px;">
            Imported <?= $importResult['imported'] ?> <?= $importResult['imported'] === 1 ? 'entry' : 'entries' ?>.
            <?php if ($importResult['skipped_duplicates'] > 0): ?>
            Skipped <?= $importResult['skipped_duplicates'] ?> duplicate<?= $importResult['skipped_duplicates'] === 1 ? '' : 's' ?>.
            <?php endif; ?>
        </p>
        <?php if ($importResult['errors']): ?>
        <ul style="margin:8px 0 0;padding-left:20px;font-size:13px;color:var(--color-muted);">
            <?php foreach ($importResult['errors'] as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php elseif ($importError !== null): ?>
    <div style="margin-bottom:16px;padding:12px 16px;background:var(--color-canvas);border:1px solid var(--color-border);border-radius:8px;color:var(--color-muted);">
        <?= htmlspecialchars($importError) ?>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/import"
          enctype="multipart/form-data" style="margin-bottom:16px;">
        <?= csrf_field() ?>
        <div style="margin-bottom:12px;display:flex;align-items:center;gap:12px;">
            <input type="file" id="csv_file_input" name="csv_file" accept=".csv,text/csv" required
                   style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;">
            <label for="csv_file_input" class="btn-outline" style="display:inline-block;cursor:pointer;white-space:nowrap;">
                Choose File
            </label>
            <span id="csv_file_name" style="font-size:13px;color:var(--color-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">No file selected</span>
        </div>
        <button type="submit" id="import-btn" class="btn-primary" disabled>Import CSV</button>
    </form>
    <script>
    document.getElementById('csv_file_input').addEventListener('change', function () {
        var nameEl    = document.getElementById('csv_file_name');
        var importBtn = document.getElementById('import-btn');
        if (this.files.length > 0) {
            nameEl.textContent    = this.files[0].name;
            importBtn.disabled    = false;
        } else {
            nameEl.textContent = 'No file selected';
            importBtn.disabled = true;
        }
    });
    </script>
    <p style="margin:0 0 16px;font-size:13px;color:var(--color-muted);">
        Supported formats: gistats export CSV and Poopify export CSV.
        Duplicate entries (same timestamp) are automatically skipped.
    </p>

    <a href="<?= htmlspecialchars($config['base_url']) ?>/export" class="btn-outline" style="display:inline-block;">Export CSV</a>
</div>
```

- [ ] **Step 4: Remove the standalone Export/Import nav links**

In `views/layout.php`, replace:

```php
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/export" style="color:var(--color-text);text-decoration:none;font-size:15px;">Export CSV</a></li>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/import" style="color:var(--color-text);text-decoration:none;font-size:15px;">Import CSV</a></li>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/about" style="color:var(--color-text);text-decoration:none;font-size:15px;">About</a></li>
```

with:

```php
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/about" style="color:var(--color-text);text-decoration:none;font-size:15px;">About</a></li>
```

- [ ] **Step 5: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions. (`ImportTest.php` tests `import_csv()` directly and is unaffected by this routing change.)

- [ ] **Step 6: Manually verify in a browser**

```bash
pitchfork start
```

Confirm the nav drawer no longer shows "Export CSV"/"Import CSV", that `/admin` now shows both the import form and an Export CSV link, that importing a CSV shows its result inline on the Admin page, and that clicking Export CSV still downloads a file.

- [ ] **Step 7: Commit**

```bash
git add index.php views/admin.php views/layout.php
git rm views/import.php
git commit -m "feat: move import/export from nav drawer into admin page"
```

---

### Task 4: Backup

**Files:**
- Create: `lib/backup.php`
- Modify: `index.php`, `views/admin.php`
- Test: `tests/BackupTest.php` (new)

**Interfaces:**
- Produces: `create_backup(string $dbPath): string` in `lib/backup.php`, returning the path to a temp SQLite file the caller is responsible for deleting. `GET /admin/backup` route.

- [ ] **Step 1: Write the failing test**

Create `tests/BackupTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/backup.php';

class BackupTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_backup_src_' . uniqid() . '.sqlite';
        DB::reset();
        DB::init(['db_path' => $this->dbPath]);
        DB::createSchema();
        DB::execute(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)',
            ['admin', password_hash('secret', PASSWORD_BCRYPT)]
        );
        DB::execute(
            'INSERT INTO entries (user_id, occurred_at, stool_type) VALUES (?, ?, ?)',
            [1, '2026-05-27T12:00:00Z', 4]
        );
    }

    protected function tearDown(): void
    {
        DB::reset();
        if (file_exists($this->dbPath)) unlink($this->dbPath);
    }

    public function testCreateBackupProducesReadableCopy(): void
    {
        $backupPath = create_backup($this->dbPath);
        try {
            $this->assertFileExists($backupPath);
            $backupPdo = new PDO('sqlite:' . $backupPath);
            $backupPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $row = $backupPdo->query('SELECT * FROM entries WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('2026-05-27T12:00:00Z', $row['occurred_at']);
        } finally {
            if (file_exists($backupPath)) unlink($backupPath);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/BackupTest.php`
Expected: FAIL — `lib/backup.php` doesn't exist yet.

- [ ] **Step 3: Implement `create_backup()`**

Create `lib/backup.php`:

```php
<?php
// lib/backup.php

function create_backup(string $dbPath): string
{
    $tempPath    = sys_get_temp_dir() . '/gistats_backup_' . bin2hex(random_bytes(8)) . '.sqlite';
    $escapedPath = str_replace("'", "''", $tempPath);
    DB::execute("VACUUM INTO '{$escapedPath}'");
    return $tempPath;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/BackupTest.php`
Expected: PASS

- [ ] **Step 5: Add `require_once` and the `/admin/backup` route**

In `index.php`, add to the `require_once` block (after `require_once __DIR__ . '/lib/csrf.php';`):

```php
require_once __DIR__ . '/lib/backup.php';
```

Then insert this route right after `$router->post('/admin/password', ...)`'s closing `});`:

```php
$router->get('/admin/backup', function () use ($config) {
    require_auth();
    $backupPath = create_backup($config['db_path']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="gistats-backup-' . date('Ymd-His') . '.sqlite"');
    register_shutdown_function('unlink', $backupPath);
    readfile($backupPath);
    exit;
});
```

- [ ] **Step 6: Add the Backup section to the admin view**

In `views/admin.php`, add this after the `Import / Export` `</div>` and before the end of the file:

```php

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Backup</h3>
    <p style="margin:0 0 16px;font-size:13px;color:var(--color-muted);">
        Downloads a complete, consistent copy of your database.
    </p>
    <a href="<?= htmlspecialchars($config['base_url']) ?>/admin/backup" class="btn-primary" style="display:inline-block;">Download Backup</a>
</div>
```

- [ ] **Step 7: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 8: Manually verify in a browser**

```bash
pitchfork start
```

Visit `/admin`, click "Download Backup", and confirm a `.sqlite` file downloads with a `gistats-backup-<timestamp>.sqlite` filename. Open it (e.g. `sqlite3 ~/Downloads/gistats-backup-*.sqlite ".tables"` if the `sqlite3` CLI is available locally) and confirm it contains your data.

- [ ] **Step 9: Commit**

```bash
git add lib/backup.php index.php views/admin.php tests/BackupTest.php
git commit -m "feat: add database backup download to admin page"
```

---

### Task 5: Restore

**Files:**
- Modify: `lib/backup.php`, `index.php`, `views/admin.php`
- Test: `tests/BackupTest.php`

**Interfaces:**
- Consumes: `create_backup()` (Task 4) — used by this task's test to build a realistic upload fixture.
- Produces: `validate_sqlite_upload(string $tmpPath): ?string` (returns an error string, or `null` if valid) and `restore_from_upload(string $dbPath, string $uploadedTmpPath): void` in `lib/backup.php`. `POST /admin/restore` route.

- [ ] **Step 1: Write the failing tests**

Add to `tests/BackupTest.php` (inside the `BackupTest` class, after `testCreateBackupProducesReadableCopy`):

```php
    public function testValidateSqliteUploadAcceptsValidDatabase(): void
    {
        $error = validate_sqlite_upload($this->dbPath);
        $this->assertNull($error);
    }

    public function testValidateSqliteUploadRejectsNonSqliteFile(): void
    {
        $fakePath = sys_get_temp_dir() . '/gistats_not_sqlite_' . uniqid() . '.txt';
        file_put_contents($fakePath, 'not a database');
        try {
            $error = validate_sqlite_upload($fakePath);
            $this->assertNotNull($error);
        } finally {
            unlink($fakePath);
        }
    }

    public function testValidateSqliteUploadRejectsWrongSchema(): void
    {
        $wrongSchemaPath = sys_get_temp_dir() . '/gistats_wrong_schema_' . uniqid() . '.sqlite';
        $pdo = new PDO('sqlite:' . $wrongSchemaPath);
        $pdo->exec('CREATE TABLE something_else (id INTEGER)');
        unset($pdo);
        try {
            $error = validate_sqlite_upload($wrongSchemaPath);
            $this->assertNotNull($error);
        } finally {
            unlink($wrongSchemaPath);
        }
    }

    public function testRestoreFromUploadReplacesLiveDatabase(): void
    {
        // Build the "upload" the same way a real one originates: a source db, backed up via create_backup().
        $sourcePath = sys_get_temp_dir() . '/gistats_restore_source_' . uniqid() . '.sqlite';
        DB::reset();
        DB::init(['db_path' => $sourcePath]);
        DB::createSchema();
        DB::execute(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)',
            ['restored-user', password_hash('x', PASSWORD_BCRYPT)]
        );
        $uploadPath = create_backup($sourcePath);
        DB::reset();
        unlink($sourcePath);

        try {
            restore_from_upload($this->dbPath, $uploadPath);

            $this->assertFileDoesNotExist($this->dbPath . '-wal');
            $this->assertFileDoesNotExist($this->dbPath . '-shm');

            DB::init(['db_path' => $this->dbPath]);
            $user = DB::fetch('SELECT * FROM users WHERE username = ?', ['restored-user']);
            $this->assertNotNull($user);
        } finally {
            if (file_exists($uploadPath)) unlink($uploadPath);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/BackupTest.php`
Expected: FAIL — `validate_sqlite_upload()` and `restore_from_upload()` don't exist yet.

- [ ] **Step 3: Implement `validate_sqlite_upload()` and `restore_from_upload()`**

Add to `lib/backup.php` (after `create_backup()`):

```php
function validate_sqlite_upload(string $tmpPath): ?string
{
    $handle = fopen($tmpPath, 'rb');
    if ($handle === false) {
        return 'Unable to read uploaded file.';
    }
    $header = fread($handle, 16);
    fclose($handle);
    if ($header !== "SQLite format 3\0") {
        return 'The uploaded file is not a valid SQLite database.';
    }

    try {
        $testPdo = new PDO('sqlite:' . $tmpPath);
        $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $testPdo->query('SELECT COUNT(*) FROM users')->fetch();
    } catch (\PDOException $e) {
        return 'The uploaded file does not match the expected database schema.';
    }

    return null;
}

function restore_from_upload(string $dbPath, string $uploadedTmpPath): void
{
    DB::reset();

    $walPath = $dbPath . '-wal';
    $shmPath = $dbPath . '-shm';
    if (file_exists($walPath)) {
        unlink($walPath);
    }
    if (file_exists($shmPath)) {
        unlink($shmPath);
    }

    $swapPath = dirname($dbPath) . '/.' . basename($dbPath) . '.restoring';
    copy($uploadedTmpPath, $swapPath);
    rename($swapPath, $dbPath);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/BackupTest.php`
Expected: PASS

- [ ] **Step 5: Add the `/admin/restore` route**

Insert into `index.php`, right after the `$router->get('/admin/backup', ...)` block:

```php
$router->post('/admin/restore', function () use ($config) {
    require_auth();
    require_csrf();

    $uploadError = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        render('admin', ['restoreError' => 'The uploaded file exceeds the maximum allowed size.']);
        return;
    }
    if (empty($_FILES['backup_file']['tmp_name']) || $uploadError !== UPLOAD_ERR_OK) {
        render('admin', ['restoreError' => 'No file uploaded or upload error.']);
        return;
    }

    $validationError = validate_sqlite_upload($_FILES['backup_file']['tmp_name']);
    if ($validationError !== null) {
        render('admin', ['restoreError' => $validationError]);
        return;
    }

    restore_from_upload($config['db_path'], $_FILES['backup_file']['tmp_name']);
    logout();
    header('Location: /login');
    exit;
});
```

- [ ] **Step 6: Add the Restore section to the admin view**

In `views/admin.php`, add the `$restoreError` default near the other view-variable defaults at the top:

Replace:

```php
$importResult    = $importResult    ?? null;
$importError     = $importError     ?? null;
?>
```

with:

```php
$importResult    = $importResult    ?? null;
$importError     = $importError     ?? null;
$restoreError    = $restoreError    ?? null;
?>
```

Then add this after the Backup section's `</div>` and before the end of the file:

```php

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Restore from Backup</h3>
    <?php if ($restoreError): ?>
    <p style="color:var(--color-red);margin:0 0 16px;font-size:14px;"><?= htmlspecialchars($restoreError) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/admin/restore"
          enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px;">
        <?= csrf_field() ?>
        <div style="display:flex;align-items:center;gap:12px;">
            <input type="file" id="backup_file_input" name="backup_file" accept=".sqlite" required
                   style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;">
            <label for="backup_file_input" class="btn-outline" style="display:inline-block;cursor:pointer;white-space:nowrap;">
                Choose File
            </label>
            <span id="backup_file_name" style="font-size:13px;color:var(--color-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">No file selected</span>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--color-muted);">
            <input type="checkbox" id="restore-confirm-input" required>
            I understand this will overwrite my current data.
        </label>
        <button type="submit" id="restore-btn" class="btn-danger" disabled>Restore</button>
    </form>
    <script>
    (function () {
        var fileInput  = document.getElementById('backup_file_input');
        var nameEl     = document.getElementById('backup_file_name');
        var confirmBox = document.getElementById('restore-confirm-input');
        var restoreBtn = document.getElementById('restore-btn');
        function updateButton() {
            restoreBtn.disabled = !(fileInput.files.length > 0 && confirmBox.checked);
        }
        fileInput.addEventListener('change', function () {
            nameEl.textContent = this.files.length > 0 ? this.files[0].name : 'No file selected';
            updateButton();
        });
        confirmBox.addEventListener('change', updateButton);
    }());
    </script>
</div>
```

- [ ] **Step 7: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 8: Manually verify in a browser**

```bash
pitchfork start
```

On `/admin`, download a backup, add a new entry, then restore that earlier backup and confirm: you're logged out and redirected to `/login`; logging back in shows the data from the backup (the entry you added after it is gone); uploading a non-`.sqlite` file shows a clear error instead of touching the live database.

- [ ] **Step 9: Commit**

```bash
git add lib/backup.php index.php views/admin.php tests/BackupTest.php
git commit -m "feat: add database restore to admin page"
```

---

### Task 6: Documentation

**Files:**
- Modify: `README.md`

**Interfaces:**
- None (documentation only).

- [ ] **Step 1: Rewrite the "Setup" section**

Replace:

```markdown
Create the database and your user account:

```bash
php seed.php
```

The script prompts for a username and password, creates the SQLite database at `database.sqlite`, and applies the schema. Run it again with the same username to reset that user's password, since it uses `INSERT OR REPLACE`.

To wipe and recreate the database:

```bash
rm database.sqlite
php seed.php
```
```

with:

```markdown
Start the app (see [Tasks](#tasks) below), then open it in a browser. With no database present yet, the app creates one automatically and shows a one-time "Create your account" form — fill it in to finish setup.

To start over with a fresh database:

```bash
rm database.sqlite
```

Reloading the app recreates the schema and shows the setup form again.
```

- [ ] **Step 2: Update the Overview section's Export CSV reference**

Replace:

```markdown
Log bowel movements, view trends on a calendar, track statistics over time, and export your data as CSV. Sign in and open Export CSV in the nav drawer, or visit `/export` directly. The download includes UTC timestamps, local timestamps, duration in seconds, Bristol stool type, and notes.
```

with:

```markdown
Log bowel movements, view trends on a calendar, track statistics over time, and export your data as CSV. Sign in and open the Admin page to import, export, back up, or restore your data, or visit `/export` directly for a quick CSV download. The download includes UTC timestamps, local timestamps, duration in seconds, Bristol stool type, and notes.
```

- [ ] **Step 3: Update the project structure tree**

Replace:

```markdown
├── index.php          # Entry point: routes and render() helper
├── config.php         # Runtime configuration
├── router.php         # Simple pattern-matching router
├── server.php         # PHP built-in server router (static file passthrough)
├── seed.php           # CLI: create database and admin user
├── lib/
│   ├── db.php         # PDO/SQLite wrapper (singleton)
│   ├── helpers.php    # Pure functions: timezone, duration, calendar, stats
│   ├── entries.php    # Entry CRUD
│   ├── auth.php       # Session auth
│   └── csrf.php       # CSRF token helpers
├── views/
│   ├── layout.php     # HTML shell with nav drawer
│   ├── home.php       # Entry form + calendar + event list
│   ├── login.php      # Sign-in form
│   ├── stats.php      # Statistics with Chart.js
│   ├── about.php      # About page
│   ├── 404.php        # Standalone 404
│   └── partials/      # HTMX-targeted fragments
```

with:

```markdown
├── index.php          # Entry point: routes and render() helper
├── config.php         # Runtime configuration
├── router.php         # Simple pattern-matching router
├── server.php         # PHP built-in server router (static file passthrough)
├── lib/
│   ├── db.php         # PDO/SQLite wrapper (singleton)
│   ├── helpers.php    # Pure functions: timezone, duration, calendar, stats
│   ├── entries.php    # Entry CRUD
│   ├── auth.php       # Session auth, account setup, password change
│   ├── backup.php     # Database backup and restore
│   └── csrf.php       # CSRF token helpers
├── views/
│   ├── layout.php     # HTML shell with nav drawer
│   ├── home.php       # Entry form + calendar + event list
│   ├── login.php      # Sign-in form
│   ├── setup.php      # One-time account creation form
│   ├── admin.php      # Password change, import/export, backup, restore
│   ├── stats.php      # Statistics with Chart.js
│   ├── about.php      # About page
│   ├── 404.php        # Standalone 404
│   └── partials/      # HTMX-targeted fragments
```

- [ ] **Step 4: Update the deploy pipeline's one-time server setup and add password recovery**

Replace:

```markdown
**One-time server setup**, via SSH:

```bash
mkdir -p ~/data/gitstats
```

After the first deploy creates the `gistats/` web directory, place `~/magicbydesign.com/gistats/config.local.php` on the server:

```php
<?php return [
    'base_url' => '/gistats',
    'db_path'  => '/home/ccshell/data/gitstats/database.sqlite',
];
```

Then seed the production database:

```bash
cd ~/magicbydesign.com/gistats
php seed.php
```

`config.local.php` is gitignored and never deployed (the SFTP action never deletes remote files), so it survives every subsequent deploy.
```

with:

```markdown
**One-time server setup**, via SSH: after the first deploy creates the `gistats/` web directory, place `~/magicbydesign.com/gistats/config.local.php` on the server:

```php
<?php return [
    'base_url' => '/gistats',
    'db_path'  => '/home/ccshell/data/gitstats/database.sqlite',
];
```

`config.local.php` is gitignored and never deployed (the SFTP action never deletes remote files), so it survives every subsequent deploy. The app creates its own data directory, database, and schema automatically on first request — visit the site and fill out the one-time "Create your account" form to finish setup.

**Password recovery:** if you forget your password, SSH in and run:

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
```

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs: update setup, project structure, and deploy docs for admin panel"
```
