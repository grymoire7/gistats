<?php
// lib/csrf.php
require_once __DIR__ . '/session.php';

function csrf_token() {
    ensure_session_started();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf($token) {
    ensure_session_started();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token)) {
        die('CSRF token missing from request');
    }
    if (!verify_csrf($token)) {
        die('CSRF validation failed. Please try again. If this persists, clear your browser cookies and try again.');
    }
}
