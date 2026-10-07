<?php
/*
 |  ROLES AND PERMISSIONS
 |  ---------------------------------------------------------------------
 |  This is the ONE place that decides who can do what.
 |  The role of a user is the `position` column of the `users` table
 |  (Admin / Staff). Upper or lower case does not matter.
 |
 |  HOW TO CHANGE WHAT STAFF CAN DO
 |      Add or remove a permission name in the 'staff' list below. That is all.
 |
 |  HOW TO ADD A NEW ROLE (example: cashier)
 |      1. Add   'cashier' => ['stock.record'],   below.
 |      2. Add 'Cashier' to userPositions() in controller/auth_helpers.php.
 |
 |  PERMISSION NAMES USED BY THE SYSTEM
 |      dashboard.view   Dashboard page and its chart
 |      products.view    Products page (list, search, CSV export)
 |      products.manage  Add / edit / delete products
 |      stock.record     Stock In / Out page: record new stock movements
 |      stock.edit       Edit or delete old stock records
 |      suppliers.manage Suppliers page
 |      expenses.manage  Add / edit / delete expenses
 |      reports.view     Reports page
 |      users.manage     Users page, delete users, choose a position
 |      audit.view       (reserved) Audit trail page, when you build it
 |      settings.manage  (reserved) System settings page, when you build it
 |
 |  A role with  '*'  has every permission. A role that is not listed here
 |  has NO permission (safe default).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROLE_PERMISSIONS')) {
    define('ROLE_PERMISSIONS', [

        'admin' => ['*'],

        'staff' => [
            'dashboard.view',
            'products.view',
            'stock.record',
        ],

    ]);
}

/* ---------- reading the role ---------- */

function normalizeRole($value): string
{
    return strtolower(trim((string) $value));
}

function currentRole(): string
{
    return normalizeRole($_SESSION['user']['position'] ?? '');
}

function isAdmin(): bool
{
    return currentRole() === 'admin';
}

function roleCan(string $role, string $permission): bool
{
    $list = ROLE_PERMISSIONS[$role] ?? [];   // unknown role = no permission

    return in_array('*', $list, true) || in_array($permission, $list, true);
}

// true if the logged-in user has this permission
function can(string $permission): bool
{
    return !empty($_SESSION['user']) && roleCan(currentRole(), $permission);
}

/* ---------- keep the session role up to date ---------- */

// Gives the one database connection of this request.
// If the page already has $conn, it is reused. If not, connect.php is loaded here and the
// connection is shared as the global $conn, so a later  require_once 'connect.php'  (which
// PHP then skips) still finds $conn in the page or controller.
function rbacConnection(): mysqli
{
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        return $GLOBALS['conn'];
    }

    require_once __DIR__ . '/../database/connect.php';   // creates a local $conn here

    if (!isset($conn) || !($conn instanceof mysqli)) {
        include __DIR__ . '/../database/connect.php';     // already loaded elsewhere without $conn: connect again
    }

    $GLOBALS['conn'] = $conn;

    return $conn;
}

// Reads the user's current username and role from the database (once per request).
// Returns false if the account no longer exists (the session is cleared).
// This way a demoted or deleted user loses access immediately, not at the next login.
function refreshSessionUser(): bool
{
    static $result = null;

    if ($result !== null) {
        return $result;
    }

    if (empty($_SESSION['user']['id'])) {
        return $result = false;
    }

    try {
        $conn = rbacConnection();
        $id   = (int) $_SESSION['user']['id'];

        $stmt = $conn->prepare('SELECT username, position FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        // database problem: keep the current session instead of locking everybody out
        return $result = true;
    }

    if (!$row) {
        $_SESSION = [];
        return $result = false;
    }

    $_SESSION['user']['username'] = $row['username'];
    $_SESSION['user']['position'] = $row['position'];

    return $result = true;
}

/* ---------- guards: put one at the top of every protected page / controller ---------- */

// PAGES in the main folder (dashboard.php, users.php ...)
function requirePermission(string $permission): void
{
    if (!refreshSessionUser()) {
        $_SESSION['error'] = 'Please Login First.';
        header('Location: login.php');
        exit();
    }

    if (!can($permission)) {
        header('Location: access_denied.php');
        exit();
    }
}

// CONTROLLERS that receive a normal form (redirect back when not allowed)
function requirePermissionForm(string $permission): void
{
    if (!refreshSessionUser()) {
        $_SESSION['error'] = 'Please Login First.';
        header('Location: ../login.php');
        exit();
    }

    if (!can($permission)) {
        header('Location: ../access_denied.php');
        exit();
    }
}

// CONTROLLERS that answer with JSON (fetch calls)
function requirePermissionApi(string $permission): void
{
    $loggedIn = refreshSessionUser();

    if ($loggedIn && can($permission)) {
        return;
    }

    http_response_code($loggedIn ? 403 : 401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $loggedIn ? 'You do not have permission to do this.' : 'Not logged in']);
    exit();
}

// CONTROLLERS that send a file (CSV export)
function requirePermissionDownload(string $permission): void
{
    $loggedIn = refreshSessionUser();

    if ($loggedIn && can($permission)) {
        return;
    }

    http_response_code($loggedIn ? 403 : 401);
    exit($loggedIn ? 'You do not have permission to do this.' : 'Not logged in');
}

/* ---------- sidebar menu (one list for the sidebar and the access-denied page) ---------- */

// key => [page, icon, label, permission needed to see it]
function navMenu(): array
{
    return [
        'dashboard' => ['dashboard.php', 'fa-chart-column',  'Dashboard',      'dashboard.view'],
        'products'  => ['products.php',  'fa-box-open',      'Products',       'products.view'],
        'stock'     => ['stock.php',     'fa-right-left',    'Stock In / Out', 'stock.record'],
        'suppliers' => ['suppliers.php', 'fa-truck-moving',  'Suppliers',      'suppliers.manage'],
        'reports'   => ['reports.php',   'fa-file-lines',    'Reports',        'reports.view'],
        'users'     => ['users.php',     'fa-people-group',  'Users',          'users.manage'],
    ];
}

// only the links the logged-in user is allowed to use
function visibleMenu(): array
{
    return array_filter(navMenu(), function ($item) {
        return can($item[3]);
    });
}

// first page the user may open (used by the access-denied page)
function firstAllowedPage(): ?string
{
    foreach (visibleMenu() as $item) {
        return $item[0];
    }

    return null;
}
