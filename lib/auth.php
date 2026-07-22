<?php
require_once __DIR__ . '/db.php';

function is_logged_in(): bool
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !empty($_SESSION['user_id']);
}

function require_auth(): void
{
    if (!is_logged_in()) {
        header('Location: /login');
        exit;
    }
}

function current_user_id(): int
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    return (int) ($_SESSION['user_id'] ?? 0);
}

function login(string $username, string $password): bool
{
    $user = DB::fetch('SELECT * FROM users WHERE username = ?', [$username]);
    if (!$user || !password_verify($password, $user['password_hash'])) return false;
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    return true;
}

function logout(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
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
