<?php
include("controller/authenticator.php");
requirePermission('products.view');   // role check: staff typing this URL are sent to access_denied.php
include("database/connect.php");
include("controller/product_helpers.php");

ensureProductUnitColumn($conn);       // adds the products.unit column the first time
$categories = productCategories();   // category name => SKU prefix
$units      = productUnits();        // unit value => text shown

// the SKU each category will get next (shown in the Add Product window)
$nextSkus = [];
foreach ($categories as $categoryName => $prefix) {
    $nextSkus[$categoryName] = nextSku($conn, $prefix);
}
if (!function_exists("h")) {
    function h($value)
    {
        return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
    }
}

function stockStatus($qty, $reorder)
{
    $qty     = (float) $qty;
    $reorder = (float) $reorder;

    if ($qty <= 0) {
        return ["Out of stock", "pm-out"];
    }
    if ($qty <= $reorder) {
        return ["Low stock", "pm-low"];
    }
    return ["In stock", "pm-ok"];
}

// "All" or "Low stock" (the dashboard's "Needs attention" link uses ?filter=low_stock)
$filter = (($_GET["filter"] ?? "") === "low_stock") ? "low_stock" : "all";
$where  = ($filter === "low_stock") ? "WHERE p.stock_qty <= p.reorder_level" : "";

// numbers for the 4 cards (always for ALL products, not just the filtered list)
$stats = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(stock_qty), 0) AS units,
        COALESCE(SUM(stock_qty <= reorder_level), 0) AS low,
        COALESCE(SUM(stock_qty * cost_price), 0) AS inv_value
    FROM products
")->fetch_assoc();

$products = [];

$productResult = $conn->query("
    SELECT
        p.id,
        p.sku,
        p.name,
        p.category,
        p.unit,
        p.supplier_id,
        p.cost_price,
        p.stock_qty,
        p.reorder_level,
        s.name AS supplier_name
    FROM products p
    LEFT JOIN suppliers s
        ON p.supplier_id = s.id
    $where
    ORDER BY p.id DESC
");

while ($row = $productResult->fetch_assoc()) {
    $products[] = $row;
}

$suppliers = [];

$supplierResult = $conn->query("
    SELECT id, name
    FROM suppliers
    ORDER BY name ASC
");

while ($row = $supplierResult->fetch_assoc()) {
    $suppliers[] = $row;
}

$editProduct = null;

if (isset($_GET["edit"])) {

    $editId = (int) $_GET["edit"];

    $stmt = $conn->prepare("
        SELECT id, sku, name, category, unit, supplier_id, cost_price, stock_qty, reorder_level
        FROM products
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $editId);
    $stmt->execute();

    $editResult = $stmt->get_result();

    if ($editResult->num_rows > 0) {
        $editProduct = $editResult->fetch_assoc();
    }

    $stmt->close();
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products</title>
    <link rel="stylesheet" href="tailwind.min.css">
    <script src="https://kit.fontawesome.com/7fa31b9a7a.js" crossorigin="anonymous"></script>
    <style>
        /* Small self-contained styles for the parts that were added/rewritten here.
           They do not depend on tailwind.min.css, so they always render. */
        .pm-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, .5); display: none; align-items: center; justify-content: center; z-index: 50; padding: 1rem; }
        .pm-overlay.open { display: flex; }
        .pm-modal { background: #fff; border-radius: 1rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, .25); width: 100%; max-width: 36rem; max-height: 90vh; overflow-y: auto; }
        .pm-badge { display: inline-block; margin-left: .5rem; padding: 2px 10px; border-radius: 9999px; font-size: .75rem; font-weight: 600; white-space: nowrap; }
        .pm-ok  { background: #dcfce7; color: #15803d; }
        .pm-low { background: #fef3c7; color: #b45309; }
        .pm-out { background: #fee2e2; color: #b91c1c; }
        .pm-chip { display: inline-block; padding: 10px 18px; border-radius: 12px; font-size: 14px; font-weight: 600; border: 1px solid #cbd5e1; color: #475569; background: #fff; text-decoration: none; }
        .pm-chip:hover { background: #f5f3ff; }
        .pm-chip.active { background: #6d28d9; border-color: #6d28d9; color: #fff; }
        .pm-table { min-width: 1000px; }
        /* input with a unit tag on its right side (quantity, reorder level, cost price) */
        .pm-group { display: flex; align-items: stretch; border: 1px solid #cbd5e1; border-radius: .75rem; overflow: hidden; background: #fff; }
        .pm-group:focus-within { border-color: #a855f7; }
        .pm-group-input { flex: 1 1 auto; min-width: 0; padding: .75rem 1rem; border: 0; background: transparent; }
        .pm-unit-tag { display: flex; align-items: center; padding: 0 .9rem; background: #f3e8ff; color: #6b21a8; font-size: .8rem; font-weight: 600; white-space: nowrap; border-left: 1px solid #e9d5ff; }
    </style>
</head>
<body class="bg-purple-200">
<div class="grid grid-cols-1 lg:grid-cols-6 min-h-screen">

    <?php
        $activePage = "products";
        include "partials/sidebar.php";
    ?>

    <main class="lg:col-span-5 bg-purple-200 min-h-screen">

        <div class="bg-purple-700 p-5 shadow-lg">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-purple-200 text-sm">Inventory Management</p>
                    <h1 class="text-2xl font-bold text-white">Products</h1>
                </div>
                <div class="flex items-center gap-3 bg-purple-600 rounded-xl px-4 py-3">
                    <div class="w-10 h-10 rounded-full bg-purple-500 flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-user text-white"></i>
                    </div>
                    <div>
                        <p class="text-xs text-purple-200">Logged in as</p>
                        <p class="text-sm font-bold text-white"><?= h(strtoupper($_SESSION["user"]["username"])) ?></p>
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

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-7">

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm text-slate-500">Total Products</p>
                            <h2 class="text-3xl font-bold text-slate-900 mt-1"><?= number_format((int) $stats["total"]) ?></h2>
                            <p class="text-xs text-green-600 mt-2">Products in inventory</p>
                        </div>
                        <div class="w-14 h-14 rounded-2xl bg-violet-100 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-box text-violet-600 text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm text-slate-500">Low Stock</p>
                            <h2 class="text-3xl font-bold text-slate-900 mt-1"><?= number_format((int) $stats["low"]) ?></h2>
                            <?php if ((int) $stats["low"] > 0): ?>
                                <a href="products.php?filter=low_stock" class="block text-xs text-amber-600 mt-2">View low stock items</a>
                            <?php else: ?>
                                <p class="text-xs text-green-600 mt-2">Everything is well stocked</p>
                            <?php endif; ?>
                        </div>
                        <div class="w-14 h-14 rounded-2xl bg-amber-100 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-triangle-exclamation text-amber-500 text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm text-slate-500">Total Items</p>
                            <h2 class="text-3xl font-bold text-slate-900 mt-1"><?= h(formatQty($stats["units"])) ?></h2>
                            <p class="text-xs text-purple-600 mt-2">Units in stock</p>
                        </div>
                        <div class="w-14 h-14 rounded-2xl bg-purple-100 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-cubes text-purple-600 text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm text-slate-500">Inventory Value</p>
                            <h2 class="text-3xl font-bold text-slate-900 mt-1">₱<?= number_format($stats["inv_value"], 2) ?></h2>
                            <p class="text-xs text-green-600 mt-2">Stock value at cost</p>
                        </div>
                        <div class="w-14 h-14 rounded-2xl bg-green-100 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-peso-sign text-green-600 text-2xl"></i>
                        </div>
                    </div>
                </div>

            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-7">

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-purple-100 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-boxes-stacked text-purple-700 text-lg"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Product Management</h2>
                            <p class="text-sm text-slate-500">Manage your inventory products</p>
                        </div>
                    </div>
                    <?php if (can('products.manage')): ?>
                    <button type="button" onclick="openAddModal()" class="bg-purple-700 hover:bg-purple-800 text-white font-semibold rounded-xl px-5 py-3">
                        <i class="fa-solid fa-plus mr-2"></i>Add Product
                    </button>
                    <?php endif; ?>
                </div>

                <div class="relative w-full mb-4">
                    <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input
                        type="text"
                        id="productSearch"
                        placeholder="Search by name, SKU, category or supplier..."
                        class="w-full border border-slate-200 rounded-xl px-4 py-3 pl-11 outline-none focus:border-purple-500"
                        style="padding-left: 2.75rem;"
                    >
                </div>

                <div class="flex flex-wrap gap-2 mb-6">
                    <a href="products.php" class="pm-chip <?= $filter === "all" ? "active" : "" ?>">
                        All Products (<?= (int) $stats["total"] ?>)
                    </a>
                    <a href="products.php?filter=low_stock" class="pm-chip <?= $filter === "low_stock" ? "active" : "" ?>">
                        Low Stock (<?= (int) $stats["low"] ?>)
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full pm-table">
                        <thead>
                            <tr class="bg-purple-100">
                                <th class="text-left text-purple-800 font-bold px-5 py-4 rounded-l-xl">Product ID</th>
                                <th class="text-left text-purple-800 font-bold px-5 py-4">SKU</th>
                                <th class="text-left text-purple-800 font-bold px-5 py-4">Product Name</th>
                                <th class="text-left text-purple-800 font-bold px-5 py-4">Category</th>
                                <th class="text-left text-purple-800 font-bold px-5 py-4">Quantity</th>
                                <th class="text-left text-purple-800 font-bold px-5 py-4">Cost Price</th>
                                <th class="text-left text-purple-800 font-bold px-5 py-4">Supplier</th>
                                <?php if (can('products.manage')): ?>
                                <th class="text-center text-purple-800 font-bold px-5 py-4 rounded-r-xl">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="productTable">

                        <?php if (count($products) > 0): ?>

                            <?php foreach ($products as $product): ?>
                                <?php [$statusLabel, $statusClass] = stockStatus((float) $product["stock_qty"], (float) $product["reorder_level"]); ?>
                                <tr class="product-row border-b border-slate-100 hover:bg-purple-50">
                                    <td class="px-5 py-5 text-slate-700 font-semibold">#<?= (int) $product["id"] ?></td>
                                    <td class="px-5 py-5 text-slate-700"><?= h($product["sku"]) ?></td>
                                    <td class="px-5 py-5">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl bg-purple-100 flex items-center justify-center flex-shrink-0">
                                                <i class="fa-solid fa-box text-purple-600"></i>
                                            </div>
                                            <span class="text-slate-700"><?= h($product["name"]) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-5 text-slate-500"><?= h($product["category"] ?: "—") ?></td>
                                    <td class="px-5 py-5">
                                        <span class="text-slate-700 font-semibold"><?= h(formatQty($product["stock_qty"])) ?></span>
                                        <span class="text-xs text-slate-400"><?= h(unitLabel($product["unit"] ?? "pcs")) ?></span>
                                        <span class="pm-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                                    </td>
                                    <td class="px-5 py-5">
                                        <span class="text-slate-700 font-semibold">₱<?= number_format($product["cost_price"], 2) ?></span>
                                        <span class="text-xs text-slate-400">/ <?= h(unitLabel($product["unit"] ?? "pcs")) ?></span>
                                    </td>
                                    <td class="px-5 py-5 text-slate-500"><?= h($product["supplier_name"] ?: "No Supplier") ?></td>
                                    <?php if (can('products.manage')): ?>
                                    <td class="px-5 py-5">
                                        <div class="flex justify-center gap-2">
                                            <button
                                                type="button"
                                                onclick='openEditModal(<?= json_encode($product, $jsonFlags) ?>)'
                                                class="inline-flex items-center gap-2 bg-purple-100 text-purple-700 hover:bg-purple-700 hover:text-white px-4 py-2 rounded-xl font-semibold"
                                            >
                                                <i class="fa-solid fa-pen"></i>Edit
                                            </button>
                                            <button
                                                type="button"
                                                onclick='deleteProduct(<?= (int) $product["id"] ?>, <?= json_encode($product["name"], $jsonFlags) ?>)'
                                                class="inline-flex items-center gap-2 bg-red-100 text-red-600 hover:bg-red-600 hover:text-white px-4 py-2 rounded-xl font-semibold"
                                            >
                                                <i class="fa-solid fa-trash"></i>Delete
                                            </button>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>

                            <tr id="noResults" style="display: none;">
                                <td colspan="8" class="text-center py-10 text-slate-500">
                                    <div class="flex flex-col items-center gap-3">
                                        <i class="fa-solid fa-magnifying-glass text-4xl text-purple-300"></i>
                                        <p>No products match your search.</p>
                                    </div>
                                </td>
                            </tr>

                        <?php else: ?>

                            <tr>
                                <td colspan="8" class="text-center py-10 text-slate-500">
                                    <div class="flex flex-col items-center gap-3">
                                        <i class="fa-solid fa-box-open text-4xl text-purple-300"></i>
                                        <p>
                                            <?= $filter === "low_stock"
                                                ? "No low stock products. Everything is well stocked."
                                                : "No products yet. Click “Add Product” to create one." ?>
                                        </p>
                                    </div>
                                </td>
                            </tr>

                        <?php endif; ?>

                        </tbody>
                    </table>
                </div>

                <div class="mt-5">
                    <p class="text-sm text-slate-500">
                        Showing <span id="visibleCount"><?= count($products) ?></span>
                        of <?= (int) $stats["total"] ?> entries
                    </p>
                </div>

            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-file-lines text-purple-700 text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Reports</h2>
                            <p class="text-sm text-slate-500 mt-1">
                                Download an inventory report you can open in Excel<?= $filter === "low_stock" ? " (low stock items only)" : "" ?>.
                            </p>
                        </div>
                    </div>
                    <a
                        href="controller/export_products.php<?= $filter === "low_stock" ? "?filter=low_stock" : "" ?>"
                        class="bg-purple-700 hover:bg-purple-800 text-white font-semibold rounded-xl px-5 py-3"
                        style="display: inline-block; text-align: center;"
                    >
                        <i class="fa-solid fa-file-arrow-down mr-2"></i>Download Report
                    </a>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- One modal used for BOTH Add and Edit -->
<div id="productModal" class="pm-overlay">
    <div class="pm-modal">

        <div class="flex items-center justify-between p-6 border-b">
            <div>
                <h2 id="modalTitle" class="text-xl font-bold text-slate-900">Add Product</h2>
                <p id="modalSubtitle" class="text-sm text-slate-500 mt-1">Add a new product to your inventory</p>
            </div>
            <button type="button" onclick="closeModal()" class="w-10 h-10 rounded-xl hover:bg-slate-100">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form id="productForm" action="controller/product.php" method="POST" class="p-6">

            <?= csrfField() ?>
            <input type="hidden" name="action" id="form_action" value="create">
            <input type="hidden" name="id" id="form_id" value="" disabled>

            <div class="bg-purple-100 text-purple-800 rounded-xl px-4 py-3 mb-5 text-sm">
                <i class="fa-solid fa-barcode mr-2"></i><span id="skuText"></span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div style="grid-column: 1 / -1;">
                    <label class="block text-sm font-semibold mb-2" for="f_name">Product Name</label>
                    <input type="text" name="name" id="f_name" required maxlength="150" placeholder="e.g. Classic Milk Tea 16oz"
                        class="w-full border border-slate-300 rounded-xl px-4 py-3 outline-none focus:border-purple-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="f_category">Category</label>
                    <select name="category" id="f_category" required
                        class="w-full border border-slate-300 rounded-xl px-4 py-3 outline-none focus:border-purple-500">
                        <option value="" disabled selected>Select category</option>
                        <?php foreach ($categories as $categoryName => $prefix): ?>
                            <option value="<?= h($categoryName) ?>"><?= h($categoryName) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Groups the item and sets its SKU code.</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="f_unit">Unit</label>
                    <select name="unit" id="f_unit" required
                        class="w-full border border-slate-300 rounded-xl px-4 py-3 outline-none focus:border-purple-500">
                        <option value="" disabled selected>Select unit</option>
                        <?php foreach ($units as $unitValue => $unitText): ?>
                            <option value="<?= h($unitValue) ?>"><?= h($unitText) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">How this item is counted. Stock In / Out use it. Only <strong>kilo</strong> allows decimals (e.g. 2.5).</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="f_supplier_id">
                        Supplier <span class="text-slate-400 font-normal">(optional)</span>
                    </label>
                    <select name="supplier_id" id="f_supplier_id"
                        class="w-full border border-slate-300 rounded-xl px-4 py-3 outline-none focus:border-purple-500">
                        <option value="">No supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= (int) $supplier["id"] ?>"><?= h($supplier["name"]) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <p class="text-xs text-slate-400 mt-1">Who you buy it from. Leave empty for items you make.</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="f_cost_price">Cost Price (₱)</label>
                    <div class="pm-group">
                        <input type="number" name="cost_price" id="f_cost_price" min="0" step="0.01" value="0" required onfocus="this.select()"
                            class="pm-group-input outline-none">
                        <span class="pm-unit-tag">per <span class="js-unit-name">unit</span></span>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">What you pay for one <span class="js-unit-name">unit</span> of this item.</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="f_stock_qty">Stock Quantity</label>
                    <div class="pm-group">
                        <input type="number" name="stock_qty" id="f_stock_qty" min="0" step="1" value="0" required onfocus="this.select()"
                            class="pm-group-input outline-none">
                        <span class="pm-unit-tag js-unit-name">unit</span>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="f_reorder_level">Reorder Level</label>
                    <div class="pm-group">
                        <input type="number" name="reorder_level" id="f_reorder_level" min="0" step="1" value="10" required onfocus="this.select()"
                            class="pm-group-input outline-none">
                        <span class="pm-unit-tag js-unit-name">unit</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Shows as “Low stock” when quantity reaches this number.</p>
                </div>

            </div>

            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="closeModal()" class="px-5 py-3 border border-slate-300 rounded-xl">Cancel</button>
                <button type="submit" id="submitBtn" class="px-5 py-3 bg-purple-700 hover:bg-purple-800 text-white rounded-xl font-semibold">
                    <i class="fa-solid fa-plus mr-2"></i>Add Product
                </button>
            </div>

        </form>
    </div>
</div>

<script>
const CSRF_TOKEN  = <?= json_encode(csrfToken()) ?>;
const modal       = document.getElementById("productModal");
const form        = document.getElementById("productForm");
const searchInput = document.getElementById("productSearch");

/* ---------- search ---------- */
function searchProducts() {
    const value = searchInput.value.toLowerCase().trim();
    const rows  = document.querySelectorAll(".product-row");
    let visible = 0;

    rows.forEach(function (row) {
        const match = row.textContent.toLowerCase().includes(value);
        row.style.display = match ? "" : "none";
        if (match) visible++;
    });

    document.getElementById("visibleCount").textContent = visible;

    const none = document.getElementById("noResults");
    if (none) {
        none.style.display = (rows.length > 0 && visible === 0) ? "" : "none";
    }
}

searchInput.addEventListener("input", searchProducts);

/* ---------- add / edit modal ---------- */
const NEXT_SKUS = <?= json_encode($nextSkus, $jsonFlags) ?>;

const categorySelect = document.getElementById("f_category");

/* Only KILO products can have decimal quantities (2.5 kilo). Every other unit is a whole number. */
const unitSelect = document.getElementById("f_unit");

const UNIT_LABELS = <?= json_encode($units, $jsonFlags) ?>;

function applyUnitStep() {
    const decimal = unitSelect.value === "kilo";

    // the unit tag next to quantity, reorder level and cost price shows the chosen unit
    const unitText = UNIT_LABELS[unitSelect.value] || "unit";
    document.querySelectorAll(".js-unit-name").forEach(function (node) {
        node.textContent = unitText;
    });

    ["f_stock_qty", "f_reorder_level"].forEach(function (id) {
        document.getElementById(id).step = decimal ? "0.001" : "1";
    });
}

unitSelect.addEventListener("change", applyUnitStep);

function showSku(text) {
    document.getElementById("skuText").textContent = text;
}

// Add mode: show the SKU the product will get, based on the chosen category
function updateSkuPreview() {
    const category = categorySelect.value;

    showSku(category && NEXT_SKUS[category]
        ? "SKU (automatic): " + NEXT_SKUS[category]
        : "SKU is created automatically when you choose a category, for example MLT-0001.");
}

categorySelect.addEventListener("change", function () {
    if (document.getElementById("form_action").value === "create") {
        updateSkuPreview();
    }
});

function setMode(mode) {
    const editing = (mode === "update");

    document.getElementById("form_action").value = mode;
    document.getElementById("form_id").disabled  = !editing;

    document.getElementById("modalTitle").textContent    = editing ? "Edit Product" : "Add Product";
    document.getElementById("modalSubtitle").textContent = editing ? "Update product information" : "Add a new product to your inventory";

    document.getElementById("submitBtn").innerHTML = editing
        ? '<i class="fa-solid fa-floppy-disk mr-2"></i>Save Changes'
        : '<i class="fa-solid fa-plus mr-2"></i>Add Product';
}

function removeLegacyOptions() {
    categorySelect.querySelectorAll("option[data-legacy]").forEach(function (option) {
        option.remove();
    });
}

function openAddModal() {
    form.reset();
    removeLegacyOptions();
    setMode("create");
    applyUnitStep();
    updateSkuPreview();
    modal.classList.add("open");
    document.getElementById("f_name").focus();
}

function openEditModal(product) {
    form.reset();
    removeLegacyOptions();
    setMode("update");

    // an old product may have a category that is not in the list: keep it selectable
    if (product.category && !Array.from(categorySelect.options).some(function (o) { return o.value === product.category; })) {
        const legacy = document.createElement("option");
        legacy.value = product.category;
        legacy.textContent = product.category;
        legacy.dataset.legacy = "1";
        categorySelect.appendChild(legacy);
    }

    showSku("SKU: " + (product.sku || "—") + " (cannot be changed)");

    document.getElementById("form_id").value          = product.id;
    document.getElementById("f_name").value           = product.name;
    categorySelect.value                              = product.category || "";
    document.getElementById("f_unit").value           = product.unit || "pcs";
    document.getElementById("f_supplier_id").value    = product.supplier_id || "";
    document.getElementById("f_cost_price").value     = product.cost_price;
    document.getElementById("f_stock_qty").value      = product.stock_qty;
    document.getElementById("f_reorder_level").value  = product.reorder_level;
    applyUnitStep();

    // 12.000 -> 12, 2.500 -> 2.5
    ["f_stock_qty", "f_reorder_level"].forEach(function (id) {
        const box = document.getElementById(id);
        box.value = String(parseFloat(box.value));
    });

    modal.classList.add("open");
}

function closeModal() {
    modal.classList.remove("open");
}

modal.addEventListener("click", function (event) {
    if (event.target === modal) closeModal();
});

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") closeModal();
});

/* ---------- delete ---------- */
function deleteProduct(id, name) {
    if (!confirm('Are you sure you want to delete "' + name + '"?')) {
        return;
    }

    const f = document.createElement("form");
    f.method = "POST";
    f.action = "controller/product.php";

    const action = document.createElement("input");
    action.type  = "hidden";
    action.name  = "action";
    action.value = "delete";

    const productId = document.createElement("input");
    productId.type  = "hidden";
    productId.name  = "id";
    productId.value = id;

    const csrf = document.createElement("input");
    csrf.type  = "hidden";
    csrf.name  = "csrf";
    csrf.value = CSRF_TOKEN;

    f.appendChild(csrf);
    f.appendChild(action);
    f.appendChild(productId);
    document.body.appendChild(f);
    f.submit();
}

/* ---------- success / error message: fade out + clean the URL ---------- */
(function () {
    const url = new URL(window.location.href);

    if (url.searchParams.has("success") || url.searchParams.has("error")) {
        url.searchParams.delete("success");
        url.searchParams.delete("error");
        window.history.replaceState({}, "", url.pathname + url.search);
    }

    const flash = document.getElementById("flash");

    if (flash) {
        setTimeout(function () {
            flash.style.transition = "opacity .5s";
            flash.style.opacity = "0";
            setTimeout(function () { flash.remove(); }, 500);
        }, 4000);
    }
})();

<?php if ($editProduct): ?>
openEditModal(<?= json_encode($editProduct, $jsonFlags) ?>);
<?php endif; ?>
</script>

</body>
</html>