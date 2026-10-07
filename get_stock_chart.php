<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

require_once __DIR__ . '/permissions.php';
requirePermissionApi('dashboard.view');

require_once __DIR__ . '/dashboard_data.php';

$allowed = ['hourly', 'daily', 'monthly'];
$range   = $_GET['range'] ?? 'daily';

if (!in_array($range, $allowed, true)) {
    $range = 'daily';
}

try {
    echo json_encode(getStockChart($conn, $range));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load chart data']);
}
