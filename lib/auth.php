<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

function is_logged_in(): bool
{
    ensure_session_started();
    return !empty($_SESSION['user_id']);
}

function set_base_path(string $basePath): void
{
    $GLOBALS['gistats_base_path'] = rtrim($basePath, '/');
}

function login_url(): string
{
    return ($GLOBALS['gistats_base_path'] ?? '') . '/login';
}

function require_auth(): void
{
    if (!is_logged_in()) {
        header('Location: ' . login_url());
        exit;
    }
}

function current_user_id(): int
{
    ensure_session_started();
    return (int) ($_SESSION['user_id'] ?? 0);
}

function login(string $username, string $password): bool
{
    $user = DB::fetch('SELECT * FROM users WHERE username = ?', [$username]);
    if (!$user || !password_verify($password, $user['password_hash'])) return false;
    ensure_session_started();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    return true;
}

function logout(): void
{
    ensure_session_started();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

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

function validate_new_account(string $username, string $password, string $confirm): array
{
    $errors = [];
    if ($username === '') { $errors[] = 'Username is required.'; }
    if (strlen($password) < 8) { $errors[] = 'Password must be at least 8 characters.'; }
    if ($password !== $confirm) { $errors[] = 'Passwords do not match.'; }
    return $errors;
}

function change_password(int $userId, string $currentPassword, string $newPassword): bool
{
    $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        return false;
    }
    DB::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($newPassword, PASSWORD_BCRYPT), $userId]);
    return true;
}

function reset_password(string $username, string $newPassword): bool
{
    $user = DB::fetch('SELECT id FROM users WHERE username = ?', [$username]);
    if (!$user) {
        return false;
    }
    DB::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($newPassword, PASSWORD_BCRYPT), $user['id']]);
    return true;
}

function validate_new_password(string $newPassword, string $confirm): array
{
    $errors = [];
    if (strlen($newPassword) < 8) { $errors[] = 'New password must be at least 8 characters.'; }
    if ($newPassword !== $confirm) { $errors[] = 'New passwords do not match.'; }
    return $errors;
}
