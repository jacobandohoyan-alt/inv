<?php
/*
 |  Downloads the inventory as a CSV file (opens in Excel).
 |  controller/export_products.php                  -> all products
 |  controller/export_products.php?filter=low_stock -> low stock only
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// only logged-in users
if (empty($_SESSION['user'])) {
    http_response_code(401);
    exit('Not logged in');
}

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/unit_helpers.php';
requirePermissionDownload('products.view');

require_once __DIR__ . '/../database/connect.php';
ensureProductUnitColumn($conn);

date_default_timezone_set('Asia/Manila');

$lowOnly = (($_GET['filter'] ?? '') === 'low_stock');
$where   = $lowOnly ? 'WHERE p.stock_qty <= p.reorder_level' : '';

$result = $conn->query("
    SELECT
        p.id,
        p.sku,
        p.name,
        p.category,
        p.unit,
        s.name AS supplier,
        p.cost_price,
        p.stock_qty,
        p.reorder_level,
        (p.stock_qty * p.cost_price) AS stock_value
    FROM products p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    $where
    ORDER BY p.name ASC
");

$filename = 'inventory_report_' . date('Y-m-d_His') . ($lowOnly ? '_low_stock' : '') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

// makes Excel read the file as UTF-8
fwrite($out, "\xEF\xBB\xBF");

// stops Excel from running text that starts with = + - @ as a formula
function safeText($value)
{
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

fputcsv($out, [
    'Product ID', 'SKU', 'Product Name', 'Category', 'Unit', 'Supplier',
    'Cost Price (per unit)', 'Quantity', 'Reorder Level', 'Status', 'Stock Value (at cost)',
], ',', '"', '\\');

while ($r = $result->fetch_assoc()) {

    $qty = (float) $r['stock_qty'];

    if ($qty <= 0) {
        $status = 'Out of stock';
    } elseif ($qty <= (float) $r['reorder_level']) {
        $status = 'Low stock';
    } else {
        $status = 'In stock';
    }

    fputcsv($out, [
        $r['id'],
        safeText($r['sku']),
        safeText($r['name']),
        safeText($r['category']),
        safeText(unitLabel($r['unit'] ?? 'pcs')),
        safeText($r['supplier'] ?? 'No Supplier'),
        number_format((float) $r['cost_price'], 2, '.', ''),
        formatQty($qty, false),
        formatQty($r['reorder_level'], false),
        $status,
        number_format((float) $r['stock_value'], 2, '.', ''),
    ], ',', '"', '\\');
}

fclose($out);