<?php
/*
 |  Shared helpers for login, registration and user management.
 |   - password hashing (bcrypt) with support for old md5 accounts
 |   - CSRF tokens for forms that change data
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- passwords ---------- */

// bcrypt hashes are 60 characters; the old md5 hashes were only 32.
// If the `users.password` column is still too short we keep md5 so nobody gets locked out.
// Fix once with:  ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL;
function passwordColumnFits(mysqli $conn): bool
{
    static $fits = null;

    if ($fits === null) {
        $res = $conn->query("
            SELECT CHARACTER_MAXIMUM_LENGTH
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password'
        ");
        $len  = $res ? $res->fetch_row()[0] : null;
        $fits = $len === null || (int) $len >= 60;
    }

    return $fits;
}

function hashPassword(mysqli $conn, string $plain): string
{
    return passwordColumnFits($conn) ? password_hash($plain, PASSWORD_DEFAULT) : md5($plain);
}

// true if $plain matches $stored (new bcrypt hash OR old md5 hash)
function checkPassword(string $plain, string $stored): bool
{
    if (strlen($stored) === 32 && ctype_xdigit($stored)) {
        return hash_equals(strtolower($stored), md5($plain));
    }

    return password_verify($plain, $stored);
}

// should this stored hash be replaced with a stronger one?
function needsRehash(mysqli $conn, string $stored): bool
{
    if (!passwordColumnFits($conn)) {
        return false;
    }

    return (strlen($stored) === 32 && ctype_xdigit($stored)) || password_needs_rehash($stored, PASSWORD_DEFAULT);
}

/* ---------- CSRF ---------- */

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . csrfToken() . '">';
}

function csrfValid(): bool
{
    $sent = (string) ($_POST['csrf'] ?? '');

    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/* ---------- misc ---------- */

function userPositions(): array
{
    return ['Admin', 'Staff'];
}
