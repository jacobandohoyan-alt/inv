<?php

include('controller/authenticator.php');
requirePermission('suppliers.manage');   // role check: staff typing this URL are sent to access_denied.php
include('database/connect.php');
include('controller/expense_helpers.php');

date_default_timezone_set('Asia/Manila');
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

function cleanDate($value)
{
    $value = is_string($value) ? $value : '';
    $date  = DateTime::createFromFormat('Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : '';
}

function runQuery($conn, $sql, $types, $params)
{
    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    return $stmt->get_result();
}

/* =====================================================
   SUPPLIERS
   ===================================================== */
$counts = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'active'), 0) AS active,
        COALESCE(SUM(status = 'pending'), 0) AS pending
    FROM suppliers
")->fetch_assoc();

$tab = in_array($_GET['status'] ?? '', ['active', 'pending'], true) ? $_GET['status'] : 'all';

$statusWhere = ($tab === 'all') ? '' : "WHERE s.status = '$tab'";   // $tab is only ever 'active' or 'pending'

$suppliers = $conn->query("
    SELECT
        s.*,
        (SELECT COUNT(*) FROM products p WHERE p.supplier_id = s.id) AS product_count,
        (SELECT COUNT(*) FROM expenses e WHERE e.supplier_id = s.id) AS expense_count
    FROM suppliers s
    $statusWhere
    ORDER BY s.name ASC
")->fetch_all(MYSQLI_ASSOC);

$allSuppliers = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

/* =====================================================
   EXPENSES
   ===================================================== */
$categories = expenseCategories();
$methods    = paymentMethods();

$xCat  = in_array($_GET['x_category'] ?? '', $categories, true) ? $_GET['x_category'] : '';
$xFrom = cleanDate($_GET['x_from'] ?? '');
$xTo   = cleanDate($_GET['x_to'] ?? '');
$xQ = trim(is_string($_GET['x_q'] ?? null) ? $_GET['x_q'] : '');
$xPage = max(1, (int) ($_GET['x_page'] ?? 1));
$perPage = 15;

function expLink(array $change = [])
{
    global $tab, $xCat, $xFrom, $xTo, $xQ;

    $params = array_merge(
        ['status' => $tab === 'all' ? '' : $tab, 'x_category' => $xCat, 'x_from' => $xFrom, 'x_to' => $xTo, 'x_q' => $xQ],
        $change
    );

    $params = array_filter($params, function ($v) {
        return $v !== '' && $v !== null;
    });

    return 'suppliers.php' . ($params ? '?' . http_build_query($params) : '') . '#expenses';
}

$xc = [];
$xt = '';
$xp = [];

if ($xCat !== '') {
    $xc[] = 'e.category = ?';
    $xt  .= 's';
    $xp[] = $xCat;
}

if ($xFrom !== '') {
    $xc[] = 'e.expense_date >= ?';
    $xt  .= 's';
    $xp[] = $xFrom;
}

if ($xTo !== '') {
    $xc[] = 'e.expense_date <= ?';
    $xt  .= 's';
    $xp[] = $xTo;
}

if ($xQ !== '') {
    $like = '%' . addcslashes($xQ, '%_\\') . '%';
    $xc[] = '(e.description LIKE ? OR s.name LIKE ?)';
    $xt  .= 'ss';
    $xp[] = $like;
    $xp[] = $like;
}

$xWhere = $xc ? 'WHERE ' . implode(' AND ', $xc) : '';
$xFromSql = "FROM expenses e LEFT JOIN suppliers s ON s.id = e.supplier_id $xWhere";

$xTotals = runQuery($conn, "SELECT COUNT(*) AS n, COALESCE(SUM(e.amount), 0) AS total $xFromSql", $xt, $xp)->fetch_assoc();
$xCount  = (int) $xTotals['n'];

$monthTotal = (float) $conn->query("
    SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= '" . date('Y-m-01') . "'
")->fetch_row()[0];

$xPages  = max(1, (int) ceil($xCount / $perPage));
$xPage   = min($xPage, $xPages);
$xOffset = ($xPage - 1) * $perPage;

$expenses = runQuery(
    $conn,
    "SELECT e.*, s.name AS supplier_name $xFromSql
     ORDER BY e.expense_date DESC, e.id DESC
     LIMIT $perPage OFFSET $xOffset",
    $xt,
    $xp
)->fetch_all(MYSQLI_ASSOC);

$today = date('Y-m-d');

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Suppliers</title>

    <link
        rel="stylesheet"
        href="tailwind.min.css"
    >

    <script
        src="https://kit.fontawesome.com/7fa31b9a7a.js"
        crossorigin="anonymous">
    </script>

    <style>
        /* Styles for the parts added to this page. They do not depend on tailwind.min.css. */
        .pm-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, .5); display: none; align-items: center; justify-content: center; z-index: 50; padding: 1rem; }
        .pm-overlay.open { display: flex; }
        .pm-modal { background: #fff; border-radius: 1rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, .25); width: 100%; max-width: 36rem; max-height: 90vh; overflow-y: auto; }
        .sx-grid { display: grid; grid-template-columns: 1fr; gap: 16px; }
        @media (min-width: 640px) { .sx-grid { grid-template-columns: 1fr 1fr; } }
        .sx-full { grid-column: 1 / -1; }
        .lbl { display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; }
        .fld { width: 100%; border: 1px solid #cbd5e1; border-radius: 12px; padding: 12px 16px; outline: none; background: #fff; }
        .fld:focus { border-color: #8b5cf6; }
        .sx-btn { background: #6d28d9; color: #fff; font-weight: 600; border-radius: 12px; padding: 12px 22px; display: inline-block; }
        .sx-btn:hover { background: #5b21b6; }
        .sx-filter { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 16px; }
        .sx-field { display: flex; flex-direction: column; gap: 6px; }
        .sx-field label { font-size: 14px; font-weight: 600; }
        .sx-field input, .sx-field select { border: 1px solid #cbd5e1; border-radius: 12px; padding: 11px 14px; outline: none; background: #fff; }
        .sx-field input:focus, .sx-field select:focus { border-color: #8b5cf6; }
        .sx-grow { flex: 1; min-width: 200px; }
        .sx-table { min-width: 900px; }
        .sx-chips { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .pm-chip { display: inline-block; padding: 9px 16px; border-radius: 12px; font-size: 14px; font-weight: 600; border: 1px solid #cbd5e1; color: #475569; background: #fff; text-decoration: none; }
        .pm-chip:hover { background: #f5f3ff; }
        .pm-chip.off { opacity: .4; pointer-events: none; }
        .st-badge { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: .75rem; font-weight: 600; white-space: nowrap; }
        .st-active  { background: #dcfce7; color: #15803d; }
        .st-pending { background: #fef3c7; color: #b45309; }
        .sx-section { margin-top: 1.75rem; }
        .sx-sum { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 18px; }
        .sx-sum div { background: #f5f3ff; border-radius: 12px; padding: 12px 18px; }
        .sx-sum small { display: block; color: #64748b; font-size: 12px; }
        .sx-sum strong { font-size: 20px; color: #0f172a; }
    </style>

</head>

<body class="bg-purple-200">

    <div class="grid grid-cols-1 lg:grid-cols-6 min-h-screen">


        <?php
            $activePage = "suppliers";
            include "partials/sidebar.php";
        ?>


        <main class="lg:col-span-5 bg-purple-200 min-h-screen">


            <div class="bg-purple-700 p-5 shadow-lg">

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">


                    <div>

                        <p class="text-purple-200 text-sm">
                            Suppliers Overview
                        </p>

                        <h1 class="text-2xl font-bold text-white">
                            Supply
                        </h1>

                    </div>


                    <div class="flex items-center gap-3 bg-purple-600 rounded-xl px-4 py-3">


                        <div class="w-10 h-10 rounded-full bg-purple-500 flex items-center justify-center flex-shrink-0">

                            <i class="fa-solid fa-user text-white"></i>

                        </div>


                        <div>

                            <p class="text-xs text-purple-200">
                                Logged in as
                            </p>

                            <p class="text-sm font-bold text-white">
                                <?= h(strtoupper($_SESSION["user"]["username"])) ?>
                            </p>

                        </div>


                    </div>


                </div>

            </div>


            <div class="p-5 sm:p-6 lg:p-7">


                <?php if (isset($_GET["success"])): ?>
                    <div id="flash" class="mb-6 bg-green-100 border border-green-300 text-green-700 rounded-xl px-5 py-4">
                        <i class="fa-solid fa-circle-check mr-2"></i><?= h($_GET["success"]) ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET["error"])): ?>
                    <div id="flash" class="mb-6 bg-red-100 border border-red-300 text-red-700 rounded-xl px-5 py-4">
                        <i class="fa-solid fa-circle-exclamation mr-2"></i><?= h($_GET["error"]) ?>
                    </div>
                <?php endif; ?>


                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">


                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5 mb-6">


                        <div class="flex items-center gap-4">


                            <div class="w-14 h-14 rounded-xl bg-violet-100 flex items-center justify-center flex-shrink-0">

                                <i class="fa-solid fa-truck-moving text-violet-600 text-xl"></i>

                            </div>


                            <div>

                                <h2 class="text-xl font-bold text-slate-900">
                                    Suppliers
                                </h2>

                                <p class="text-sm text-slate-500">
                                    Supplier overview
                                </p>

                            </div>


                        </div>


                        <div class="flex items-center gap-5 text-sm">


                            <a href="suppliers.php" class="<?= $tab === 'all' ? 'text-purple-700 font-semibold border-b-2 border-purple-600 pb-1' : 'text-slate-500' ?>">
                                Overview
                            </a>


                            <a href="suppliers.php?status=active" class="<?= $tab === 'active' ? 'text-purple-700 font-semibold border-b-2 border-purple-600 pb-1' : 'text-slate-500' ?>">
                                Active
                            </a>


                            <a href="suppliers.php?status=pending" class="<?= $tab === 'pending' ? 'text-purple-700 font-semibold border-b-2 border-purple-600 pb-1' : 'text-slate-500' ?>">
                                Pending
                            </a>


                            <i class="fa-solid fa-ellipsis text-slate-500"></i>


                        </div>


                    </div>


                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">


                        <div class="rounded-xl bg-purple-50 p-5">


                            <div class="flex items-center justify-between">


                                <div>

                                    <p class="text-sm text-slate-500">
                                        Total Suppliers
                                    </p>

                                    <p class="text-3xl font-bold text-slate-900 mt-2">
                                        <?= number_format($counts['total']) ?>
                                    </p>

                                </div>


                                <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center">

                                    <i class="fa-solid fa-users text-purple-600 text-lg"></i>

                                </div>


                            </div>


                            <p class="text-sm text-purple-600 mt-4">
                                Registered suppliers
                            </p>


                        </div>


                        <div class="rounded-xl bg-green-50 p-5">


                            <div class="flex items-center justify-between">


                                <div>

                                    <p class="text-sm text-slate-500">
                                        Active Suppliers
                                    </p>

                                    <p class="text-3xl font-bold text-slate-900 mt-2">
                                        <?= number_format($counts['active']) ?>
                                    </p>

                                </div>


                                <div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center">

                                    <i class="fa-solid fa-circle-check text-green-600 text-lg"></i>

                                </div>


                            </div>


                            <p class="text-sm text-green-600 mt-4">
                                Currently active
                            </p>


                        </div>


                        <div class="rounded-xl bg-amber-50 p-5">


                            <div class="flex items-center justify-between">


                                <div>

                                    <p class="text-sm text-slate-500">
                                        Pending Suppliers
                                    </p>

                                    <p class="text-3xl font-bold text-slate-900 mt-2">
                                        <?= number_format($counts['pending']) ?>
                                    </p>

                                </div>


                                <div class="w-12 h-12 rounded-xl bg-amber-100 flex items-center justify-center">

                                    <i class="fa-solid fa-clock text-amber-500 text-lg"></i>

                                </div>


                            </div>


                            <p class="text-sm text-amber-600 mt-4">
                                Needs review
                            </p>


                        </div>


                    </div>


                </div>


                <!-- ============ 1) MANAGE SUPPLIER INFORMATION ============ -->
                <div id="suppliers" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sx-section">

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-purple-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-address-book text-purple-700 text-lg"></i>
                            </div>
                            <div>
                                <h2 class="text-xl font-bold text-slate-900">Supplier Information</h2>
                                <p class="text-sm text-slate-500">
                                    <?= $tab === 'all' ? 'All suppliers' : ucfirst($tab) . ' suppliers' ?>
                                </p>
                            </div>
                        </div>
                        <button type="button" onclick="openSupplierModal(null)" class="sx-btn">
                            <i class="fa-solid fa-plus mr-2"></i>Add Supplier
                        </button>
                    </div>

                    <div class="sx-field sx-grow" style="margin-bottom: 16px;">
                        <input type="text" id="supplierSearch" placeholder="Search suppliers by name, contact, phone or address...">
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full sx-table">
                            <thead>
                                <tr class="bg-purple-100">
                                    <th class="text-left text-purple-800 font-bold px-5 py-4 rounded-l-xl">Supplier</th>
                                    <th class="text-left text-purple-800 font-bold px-5 py-4">Contact Person</th>
                                    <th class="text-left text-purple-800 font-bold px-5 py-4">Phone / Email</th>
                                    <th class="text-left text-purple-800 font-bold px-5 py-4">Address</th>
                                    <th class="text-left text-purple-800 font-bold px-5 py-4">Status</th>
                                    <th class="text-left text-purple-800 font-bold px-5 py-4">Products</th>
                                    <th class="text-center text-purple-800 font-bold px-5 py-4 rounded-r-xl">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (count($suppliers) > 0): ?>
                                <?php foreach ($suppliers as $s): ?>
                                    <tr class="supplier-row border-b border-slate-100 hover:bg-purple-50">
                                        <td class="px-5 py-4 text-slate-700 font-semibold"><?= h($s['name']) ?></td>
                                        <td class="px-5 py-4 text-slate-500"><?= h($s['contact_person'] ?: '—') ?></td>
                                        <td class="px-5 py-4 text-slate-500">
                                            <?= h($s['phone'] ?: '—') ?>
                                            <?php if ($s['email']): ?>
                                                <br><span class="text-xs text-slate-400"><?= h($s['email']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-4 text-slate-500"><?= h($s['address'] ?: '—') ?></td>
                                        <td class="px-5 py-4">
                                            <span class="st-badge <?= $s['status'] === 'pending' ? 'st-pending' : 'st-active' ?>">
                                                <?= $s['status'] === 'pending' ? 'Pending' : 'Active' ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700 font-semibold"><?= (int) $s['product_count'] ?></td>
                                        <td class="px-5 py-4">
                                            <div class="flex justify-center gap-2">
                                                <button
                                                    type="button"
                                                    onclick='openSupplierModal(<?= json_encode($s, $jsonFlags) ?>)'
                                                    class="inline-flex items-center gap-2 bg-purple-100 text-purple-700 hover:bg-purple-700 hover:text-white px-4 py-2 rounded-xl font-semibold"
                                                >
                                                    <i class="fa-solid fa-pen"></i>Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    onclick='deleteSupplier(<?= (int) $s['id'] ?>, <?= json_encode($s['name'], $jsonFlags) ?>, <?= (int) $s['product_count'] ?>, <?= (int) $s['expense_count'] ?>)'
                                                    class="inline-flex items-center gap-2 bg-red-100 text-red-600 hover:bg-red-600 hover:text-white px-4 py-2 rounded-xl font-semibold"
                                                >
                                                    <i class="fa-solid fa-trash"></i>Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr id="noSupplier" style="display: none;">
                                    <td colspan="7" class="text-center py-10 text-slate-500">No suppliers match your search.</td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-10 text-slate-500">
                                        <div class="flex flex-col items-center gap-3">
                                            <i class="fa-solid fa-truck-moving text-4xl text-purple-300"></i>
                                            <p>No suppliers found. Click “Add Supplier” to create one.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>


                <!-- ============ 2) RECORD + 3) MANAGE BUSINESS EXPENSES ============ -->
                <div id="expenses" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sx-section">

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-amber-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-wallet text-amber-500 text-lg"></i>
                            </div>
                            <div>
                                <h2 class="text-xl font-bold text-slate-900">Business Expenses</h2>
                                <p class="text-sm text-slate-500">Rent, utilities, wages and other costs of running the shop</p>
                            </div>
                        </div>
                        <button type="button" onclick="openExpenseModal(null)" class="sx-btn">
                            <i class="fa-solid fa-plus mr-2"></i>Record Expense
                        </button>
                    </div>

                    <div class="sx-sum">
                        <div><small>This month</small><strong>₱<?= number_format($monthTotal, 2) ?></strong></div>
                        <div><small>Total for current filters</small><strong>₱<?= number_format($xTotals['total'], 2) ?></strong></div>
                        <div><small>Records</small><strong><?= number_format($xCount) ?></strong></div>
                    </div>

                    <form method="get" action="suppliers.php#expenses" class="sx-filter">
                        <?php if ($tab !== 'all'): ?>
                            <input type="hidden" name="status" value="<?= h($tab) ?>">
                        <?php endif; ?>
                        <div class="sx-field sx-grow">
                            <label for="x_q">Search</label>
                            <input type="text" id="x_q" name="x_q" value="<?= h($xQ) ?>" placeholder="Description or supplier...">
                        </div>
                        <div class="sx-field">
                            <label for="x_category">Category</label>
                            <select id="x_category" name="x_category">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= h($c) ?>" <?= $xCat === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sx-field">
                            <label for="x_from">From</label>
                            <input type="date" id="x_from" name="x_from" value="<?= h($xFrom) ?>">
                        </div>
                        <div class="sx-field">
                            <label for="x_to">To</label>
                            <input type="date" id="x_to" name="x_to" value="<?= h($xTo) ?>">
                        </div>
                        <button type="submit" class="sx-btn"><i class="fa-solid fa-filter mr-2"></i>Apply</button>
                        <a href="<?= h(expLink(['x_category' => '', 'x_from' => '', 'x_to' => '', 'x_q' => '', 'x_page' => ''])) ?>" class="pm-chip">Reset</a>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="w-full sx-table">
                            <thead>
                                <tr class="bg-purple-100">
                                    <th class="text-left text-purple-800 font-bold px-5 py-4 rounded-l-xl">Date</th>
                                    <th class="text-left text-purple-800 font-bold px-5 py-4">Category</th>
                                    <th class="text-left text-purple-800 font-bold px-5 py-4">Description</th>
                                    <th class="text-left text-purple-800 font-bold px-5 py-4">Supplier</th>
                                    <th class="text-left text-purple-800 font-bold px-5 py-4">Payment</th>
                                    <th class="text-right text-purple-800 font-bold px-5 py-4">Amount</th>
                                    <th class="text-center text-purple-800 font-bold px-5 py-4 rounded-r-xl">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (count($expenses) > 0): ?>
                                <?php foreach ($expenses as $x): ?>
                                    <tr class="border-b border-slate-100 hover:bg-purple-50">
                                        <td class="px-5 py-4 text-slate-500"><?= h(date('M j, Y', strtotime($x['expense_date']))) ?></td>
                                        <td class="px-5 py-4 text-slate-700 font-semibold"><?= h($x['category']) ?></td>
                                        <td class="px-5 py-4 text-slate-500" style="max-width: 300px;"><?= h($x['description']) ?></td>
                                        <td class="px-5 py-4 text-slate-500"><?= h($x['supplier_name'] ?: '—') ?></td>
                                        <td class="px-5 py-4 text-slate-500"><?= h($x['payment_method']) ?></td>
                                        <td class="px-5 py-4 text-right text-slate-900 font-semibold">₱<?= number_format($x['amount'], 2) ?></td>
                                        <td class="px-5 py-4">
                                            <div class="flex justify-center gap-2">
                                                <button
                                                    type="button"
                                                    onclick='openExpenseModal(<?= json_encode($x, $jsonFlags) ?>)'
                                                    class="inline-flex items-center gap-2 bg-purple-100 text-purple-700 hover:bg-purple-700 hover:text-white px-4 py-2 rounded-xl font-semibold"
                                                >
                                                    <i class="fa-solid fa-pen"></i>Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    onclick='deleteExpense(<?= (int) $x['id'] ?>, <?= json_encode($x['description'], $jsonFlags) ?>)'
                                                    class="inline-flex items-center gap-2 bg-red-100 text-red-600 hover:bg-red-600 hover:text-white px-4 py-2 rounded-xl font-semibold"
                                                >
                                                    <i class="fa-solid fa-trash"></i>Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-10 text-slate-500">
                                        <div class="flex flex-col items-center gap-3">
                                            <i class="fa-solid fa-wallet text-4xl text-purple-300"></i>
                                            <p>No expenses found. Click “Record Expense” to add one.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 mt-5">
                        <p class="text-sm text-slate-500">
                            Showing <?= $xCount === 0 ? 0 : $xOffset + 1 ?>–<?= $xOffset + count($expenses) ?> of <?= number_format($xCount) ?> record(s)
                        </p>
                        <div class="sx-chips">
                            <a href="<?= h(expLink(['x_page' => $xPage - 1])) ?>" class="pm-chip <?= $xPage <= 1 ? 'off' : '' ?>">&laquo; Previous</a>
                            <span class="text-sm text-slate-500">Page <?= $xPage ?> of <?= $xPages ?></span>
                            <a href="<?= h(expLink(['x_page' => $xPage + 1])) ?>" class="pm-chip <?= $xPage >= $xPages ? 'off' : '' ?>">Next &raquo;</a>
                        </div>
                    </div>

                </div>


            </div>


        </main>


    </div>


    <!-- ============ supplier window (add + edit) ============ -->
    <div id="supplierModal" class="pm-overlay">
        <div class="pm-modal">
            <div class="flex items-center justify-between p-6 border-b">
                <div>
                    <h2 id="supplierTitle" class="text-xl font-bold text-slate-900">Add Supplier</h2>
                    <p id="supplierSubtitle" class="text-sm text-slate-500 mt-1">Add a new supplier</p>
                </div>
                <button type="button" onclick="closeModals()" class="w-10 h-10 rounded-xl hover:bg-slate-100">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <form id="supplierForm" action="controller/supplier.php" method="POST" class="p-6">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="s_action" value="create">
                <input type="hidden" name="id" id="s_id" value="" disabled>
                <div class="sx-grid">
                    <div class="sx-full">
                        <label class="lbl" for="s_name">Supplier Name</label>
                        <input type="text" name="name" id="s_name" class="fld" required maxlength="100" placeholder="e.g. Sweet Leaf Trading">
                    </div>
                    <div>
                        <label class="lbl" for="s_contact">Contact Person</label>
                        <input type="text" name="contact_person" id="s_contact" class="fld" maxlength="100" placeholder="Full name">
                    </div>
                    <div>
                        <label class="lbl" for="s_phone">Phone</label>
                        <input type="text" name="phone" id="s_phone" class="fld" maxlength="30" placeholder="09XX-XXX-XXXX">
                    </div>
                    <div>
                        <label class="lbl" for="s_email">Email <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
                        <input type="email" name="email" id="s_email" class="fld" maxlength="100" placeholder="name@example.com">
                    </div>
                    <div>
                        <label class="lbl" for="s_status">Status</label>
                        <select name="status" id="s_status" class="fld">
                            <option value="active">Active</option>
                            <option value="pending">Pending (needs review)</option>
                        </select>
                    </div>
                    <div class="sx-full">
                        <label class="lbl" for="s_address">Address</label>
                        <input type="text" name="address" id="s_address" class="fld" maxlength="255" placeholder="Street, barangay, city">
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" onclick="closeModals()" class="px-5 py-3 border border-slate-300 rounded-xl">Cancel</button>
                    <button type="submit" id="supplierSubmit" class="sx-btn"><i class="fa-solid fa-plus mr-2"></i>Add Supplier</button>
                </div>
            </form>
        </div>
    </div>


    <!-- ============ expense window (record + edit) ============ -->
    <div id="expenseModal" class="pm-overlay">
        <div class="pm-modal">
            <div class="flex items-center justify-between p-6 border-b">
                <div>
                    <h2 id="expenseTitle" class="text-xl font-bold text-slate-900">Record Expense</h2>
                    <p id="expenseSubtitle" class="text-sm text-slate-500 mt-1">Add a business expense</p>
                </div>
                <button type="button" onclick="closeModals()" class="w-10 h-10 rounded-xl hover:bg-slate-100">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <form id="expenseForm" action="controller/expense.php" method="POST" class="p-6">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="e_action" value="create">
                <input type="hidden" name="id" id="e_id" value="" disabled>
                <div class="sx-grid">
                    <div>
                        <label class="lbl" for="e_date">Date</label>
                        <input type="date" name="expense_date" id="e_date" class="fld" required max="<?= h($today) ?>" value="<?= h($today) ?>">
                    </div>
                    <div>
                        <label class="lbl" for="e_category">Category</label>
                        <select name="category" id="e_category" class="fld" required>
                            <option value="" disabled selected>Select category</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= h($c) ?>"><?= h($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sx-full">
                        <label class="lbl" for="e_description">Description</label>
                        <input type="text" name="description" id="e_description" class="fld" required maxlength="255" placeholder="e.g. September electricity bill">
                    </div>
                    <div>
                        <label class="lbl" for="e_amount">Amount (₱)</label>
                        <input type="number" name="amount" id="e_amount" class="fld" required min="0.01" step="0.01" placeholder="0.00">
                    </div>
                    <div>
                        <label class="lbl" for="e_method">Payment Method</label>
                        <select name="payment_method" id="e_method" class="fld" required>
                            <?php foreach ($methods as $m): ?>
                                <option value="<?= h($m) ?>"><?= h($m) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sx-full">
                        <label class="lbl" for="e_supplier">Supplier <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
                        <select name="supplier_id" id="e_supplier" class="fld">
                            <option value="">No supplier</option>
                            <?php foreach ($allSuppliers as $s): ?>
                                <option value="<?= (int) $s['id'] ?>"><?= h($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" onclick="closeModals()" class="px-5 py-3 border border-slate-300 rounded-xl">Cancel</button>
                    <button type="submit" id="expenseSubmit" class="sx-btn"><i class="fa-solid fa-plus mr-2"></i>Record Expense</button>
                </div>
            </form>
        </div>
    </div>


    <script>
    const TODAY = <?= json_encode($today) ?>;

    function closeModals() {
        document.querySelectorAll(".pm-overlay").forEach(function (m) { m.classList.remove("open"); });
    }

    document.querySelectorAll(".pm-overlay").forEach(function (m) {
        m.addEventListener("click", function (event) { if (event.target === m) closeModals(); });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeModals();
    });

    function postForm(url, fields) {
        const f = document.createElement("form");
        f.method = "POST";
        f.action = url;

        fields.csrf = <?= json_encode(csrfToken()) ?>;   // CSRF token

        Object.keys(fields).forEach(function (name) {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = name;
            input.value = fields[name];
            f.appendChild(input);
        });

        document.body.appendChild(f);
        f.submit();
    }

    /* ---------- suppliers ---------- */
    function openSupplierModal(s) {
        const editing = !!s;

        document.getElementById("supplierForm").reset();

        document.getElementById("s_action").value = editing ? "update" : "create";
        document.getElementById("s_id").disabled  = !editing;
        document.getElementById("s_id").value     = editing ? s.id : "";

        document.getElementById("supplierTitle").textContent    = editing ? "Edit Supplier" : "Add Supplier";
        document.getElementById("supplierSubtitle").textContent = editing ? "Update supplier information" : "Add a new supplier";
        document.getElementById("supplierSubmit").innerHTML = editing
            ? '<i class="fa-solid fa-floppy-disk mr-2"></i>Save Changes'
            : '<i class="fa-solid fa-plus mr-2"></i>Add Supplier';

        if (editing) {
            document.getElementById("s_name").value    = s.name;
            document.getElementById("s_contact").value = s.contact_person || "";
            document.getElementById("s_phone").value   = s.phone || "";
            document.getElementById("s_email").value   = s.email || "";
            document.getElementById("s_address").value = s.address || "";
            document.getElementById("s_status").value  = s.status;
        }

        document.getElementById("supplierModal").classList.add("open");
        document.getElementById("s_name").focus();
    }

    function deleteSupplier(id, name, products, expenses) {
        let message = 'Delete the supplier "' + name + '"?';

        if (products > 0 || expenses > 0) {
            message += "\n\nIt is linked to " + products + " product(s) and " + expenses +
                       " expense record(s). They will be kept, but will have no supplier.";
        }

        message += "\n\nThis cannot be undone.";

        if (confirm(message)) {
            postForm("controller/supplier.php", { action: "delete", id: id });
        }
    }

    const supplierSearch = document.getElementById("supplierSearch");

    supplierSearch.addEventListener("input", function () {
        const value = supplierSearch.value.toLowerCase().trim();
        const rows  = document.querySelectorAll(".supplier-row");
        let visible = 0;

        rows.forEach(function (row) {
            const match = row.textContent.toLowerCase().includes(value);
            row.style.display = match ? "" : "none";
            if (match) visible++;
        });

        const none = document.getElementById("noSupplier");
        if (none) none.style.display = (rows.length > 0 && visible === 0) ? "" : "none";
    });

    /* ---------- expenses ---------- */
    function openExpenseModal(x) {
        const editing = !!x;

        document.getElementById("expenseForm").reset();

        document.getElementById("e_action").value = editing ? "update" : "create";
        document.getElementById("e_id").disabled  = !editing;
        document.getElementById("e_id").value     = editing ? x.id : "";

        document.getElementById("expenseTitle").textContent    = editing ? "Edit Expense" : "Record Expense";
        document.getElementById("expenseSubtitle").textContent = editing ? "Update this expense record" : "Add a business expense";
        document.getElementById("expenseSubmit").innerHTML = editing
            ? '<i class="fa-solid fa-floppy-disk mr-2"></i>Save Changes'
            : '<i class="fa-solid fa-plus mr-2"></i>Record Expense';

        document.getElementById("e_date").value = editing ? x.expense_date : TODAY;

        if (editing) {
            document.getElementById("e_category").value    = x.category;
            document.getElementById("e_description").value = x.description;
            document.getElementById("e_amount").value      = x.amount;
            document.getElementById("e_method").value      = x.payment_method;
            document.getElementById("e_supplier").value    = x.supplier_id || "";
        }

        document.getElementById("expenseModal").classList.add("open");
    }

    function deleteExpense(id, description) {
        if (confirm('Delete this expense?\n\n"' + description + '"\n\nThis cannot be undone.')) {
            postForm("controller/expense.php", { action: "delete", id: id });
        }
    }

    /* ---------- success / error message: fade out + clean the address ---------- */
    (function () {
        const url = new URL(window.location.href);

        if (url.searchParams.has("success") || url.searchParams.has("error")) {
            url.searchParams.delete("success");
            url.searchParams.delete("error");
            window.history.replaceState({}, "", url.pathname + url.search + url.hash);
        }

        const flash = document.getElementById("flash");

        if (flash) {
            setTimeout(function () {
                flash.style.transition = "opacity .5s";
                flash.style.opacity = "0";
                setTimeout(function () { flash.remove(); }, 500);
            }, 5000);
        }
    })();
    </script>

</body>

</html>