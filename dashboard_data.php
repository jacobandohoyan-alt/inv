<?php
/*
 |  Dashboard data (cards + chart)
 |  getDashboardStats()  -> the 8 stat cards
 |  getStockChart()      -> the "Stock Movement by Date" bar chart (Stock In vs Stock Out)
 |  peso()               -> formats money like ₱1,250.00
 */

require_once __DIR__ . '/unit_helpers.php';   // formatQty()

// Uses YOUR existing connection file (database/connect.php)
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../database/connect.php';
}

// connect.php might name the variable something else - find it
if (!isset($conn) || !($conn instanceof mysqli)) {
    foreach (['connection', 'mysqli', 'con', 'db', 'link'] as $name) {
        if (isset($$name) && $$name instanceof mysqli) {
            $conn = $$name;
            break;
        }
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new RuntimeException('database/connect.php must create a mysqli connection named $conn');
}

// Philippine time for both PHP and MySQL
date_default_timezone_set('Asia/Manila');
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");


function peso(float $amount): string
{
    $sign = $amount < 0 ? '-' : '';
    return $sign . '₱' . number_format(abs($amount), 2);
}


function getDashboardStats(mysqli $conn): array
{
    $one = function (string $sql) use ($conn) {
        $row = $conn->query($sql)->fetch_row();
        return $row[0] ?? 0;
    };

    $monthStart = date('Y-m-01 00:00:00');

    $totalProducts = (int)   $one("SELECT COUNT(*) FROM products");
    $prevProducts  = (int)   $one("SELECT COUNT(*) FROM products WHERE created_at < '$monthStart'");
    $lowStock      = (int)   $one("SELECT COUNT(*) FROM products WHERE stock_qty <= reorder_level");
    $stockIn       = (float) $one("SELECT COALESCE(SUM(quantity), 0) FROM stock_in WHERE received_at >= '$monthStart'");
    $stockOut      = (float) $one("SELECT COALESCE(SUM(quantity), 0) FROM stock_out WHERE released_at >= '$monthStart'");
    $suppliers     = (int)   $one("SELECT COUNT(*) FROM suppliers");
    $outOfStock    = (int)   $one("SELECT COUNT(*) FROM products WHERE stock_qty <= 0");
    $inventoryVal  = (float) $one("SELECT COALESCE(SUM(stock_qty * cost_price), 0) FROM products");
    $totalItems    = (float) $one("SELECT COALESCE(SUM(stock_qty), 0) FROM products");

    $growth = null;
    if ($prevProducts > 0) {
        $growth = round((($totalProducts - $prevProducts) / $prevProducts) * 100, 1);
    }

    $lowList = [];
    $res = $conn->query(
        "SELECT name, stock_qty FROM products
         WHERE stock_qty <= reorder_level
         ORDER BY stock_qty ASC, name ASC
         LIMIT 10"
    );
    while ($r = $res->fetch_assoc()) {
        $left      = rtrim(rtrim(number_format((float) $r['stock_qty'], 3, '.', ''), '0'), '.');
        $lowList[] = $r['name'] . ' (' . ($left === '' ? '0' : $left) . ' left)';
    }

    return [
        'total_products'  => $totalProducts,
        'new_this_month'  => $totalProducts - $prevProducts,
        'products_growth' => $growth,
        'low_stock'       => $lowStock,
        'low_stock_list'  => $lowList,
        'stock_in'        => $stockIn,
        'stock_out'       => $stockOut,
        'out_of_stock'    => $outOfStock,
        'total_suppliers' => $suppliers,
        'total_items'     => $totalItems,
        'inventory_value' => $inventoryVal,
    ];
}


// quantity moved per time bucket. $table is stock_in or stock_out (never user input).
function movementByBucket(mysqli $conn, string $table, string $bucketSql, string $from, string $to): array
{
    $dateCol = ($table === 'stock_in') ? 'received_at' : 'released_at';
    $bucketSql = str_replace('created_at', $dateCol, $bucketSql);

    $stmt = $conn->prepare(
        "SELECT $bucketSql AS k, SUM(quantity) AS total
         FROM $table
         WHERE $dateCol >= ? AND $dateCol < ?
         GROUP BY k"
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(string) $row['k']] = (float) $row['total'];
    }
    $stmt->close();
    return $out;
}


function niceStep(float $max): float
{
    if ($max <= 0) {
        return 100;
    }
    $raw  = $max / 6;
    $pow  = pow(10, floor(log10($raw)));
    $n    = $raw / $pow;
    $nice = $n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 2.5 ? 2.5 : ($n <= 5 ? 5 : 10)));
    return $nice * $pow;
}


function getStockChart(mysqli $conn, string $range): array
{
    $now = new DateTime();
    $fmt = 'Y-m-d H:i:s';

    $labels = [];
    $cur    = [];
    $prev   = [];

    if ($range === 'hourly') {

        $start     = new DateTime('today');
        $end       = (clone $start)->modify('+1 day');

        $curData  = movementByBucket($conn, 'stock_in',  'HOUR(created_at)', $start->format($fmt), $end->format($fmt));
        $prevData = movementByBucket($conn, 'stock_out', 'HOUR(created_at)', $start->format($fmt), $end->format($fmt));

        for ($h = 0; $h < 24; $h++) {
            $labels[] = date('g A', mktime($h, 0, 0));
            $cur[]    = $curData[(string) $h]  ?? 0;
            $prev[]   = $prevData[(string) $h] ?? 0;
        }

    } elseif ($range === 'monthly') {

        $year      = (int) $now->format('Y');
        $start     = new DateTime("$year-01-01 00:00:00");
        $end       = new DateTime(($year + 1) . '-01-01 00:00:00');

        $curData  = movementByBucket($conn, 'stock_in',  'MONTH(created_at)', $start->format($fmt), $end->format($fmt));
        $prevData = movementByBucket($conn, 'stock_out', 'MONTH(created_at)', $start->format($fmt), $end->format($fmt));

        for ($m = 1; $m <= 12; $m++) {
            $labels[] = date('M', mktime(0, 0, 0, $m, 1));
            $cur[]    = $curData[(string) $m]  ?? 0;
            $prev[]   = $prevData[(string) $m] ?? 0;
        }

    } else {

        $range     = 'daily';
        $end       = new DateTime('tomorrow');
        $start     = (clone $end)->modify('-14 days');

        $curData  = movementByBucket($conn, 'stock_in',  'DATE(created_at)', $start->format($fmt), $end->format($fmt));
        $prevData = movementByBucket($conn, 'stock_out', 'DATE(created_at)', $start->format($fmt), $end->format($fmt));

        for ($i = 0; $i < 14; $i++) {
            $day = (clone $start)->modify("+$i days");

            $labels[] = $day->format('M j');
            $cur[]    = $curData[$day->format('Y-m-d')]  ?? 0;
            $prev[]   = $prevData[$day->format('Y-m-d')] ?? 0;
        }
    }

    $step    = niceStep(max(max($cur), max($prev)));
    $yMax    = $step * 6;
    $yLabels = [];
    for ($i = 6; $i >= 0; $i--) {
        $yLabels[] = $step * $i;
    }

    return [
        'range'    => $range,
        'subtitle' => $start->format('F j, h:i a') . ' - ' . $now->format('F j, h:i a'),
        'labels'    => $labels,
        'stock_in'  => array_map(fn($v) => round($v, 3), $cur),
        'stock_out' => array_map(fn($v) => round($v, 3), $prev),
        'y_max'    => $yMax,
        'y_labels' => $yLabels,
    ];
}