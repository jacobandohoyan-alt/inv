<?php
include('controller/authenticator.php');
requirePermission('dashboard.view');   // role check: staff typing this URL are sent to access_denied.php
require_once 'controller/dashboard_data.php';
$stats = getDashboardStats($conn);
// $activePage = "dashboard";
// include "partials/sidebar.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link
        rel="stylesheet"
        href="tailwind.min.css"
    >
    <script
        src="https://kit.fontawesome.com/7fa31b9a7a.js"
        crossorigin="anonymous">
    </script>
</head>
<body class="bg-blue-50">
    <div class="grid grid-cols-1 lg:grid-cols-6 min-h-screen">
        <?php
            $activePage = "dashboard";
            include "partials/sidebar.php";
        ?>
        <main class="lg:col-span-5 bg-purple-200 min-h-screen">
            <div class="bg-purple-700 p-5 shadow-lg">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <p class="text-purple-200 text-sm">
                            Welcome back
                        </p>
                        <h1 class="text-2xl font-bold text-white">
                            Dashboard
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
                                <?= htmlspecialchars(strtoupper($_SESSION["user"]["username"]), ENT_QUOTES, "UTF-8") ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-5 sm:p-6 lg:p-7">
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 lg:gap-6">
                    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm text-slate-500">
                                    Total Products
                                </p>
                                <h3 class="text-3xl font-bold text-slate-900 mt-2">
                                    <?= number_format($stats['total_products']) ?>
                                </h3>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-violet-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-box text-violet-600 text-2xl"></i>
                            </div>
                        </div>
                        <?php if ($stats['products_growth'] === null): ?>
                            <p class="text-sm text-green-600 mt-4">
                                <i class="fa-solid fa-arrow-up mr-1"></i>
                                <?= number_format($stats['new_this_month']) ?> new this month
                            </p>
                        <?php elseif ($stats['products_growth'] >= 0): ?>
                            <p class="text-sm text-green-600 mt-4">
                                <i class="fa-solid fa-arrow-up mr-1"></i>
                                <?= $stats['products_growth'] ?>% this month
                            </p>
                        <?php else: ?>
                            <p class="text-sm text-red-600 mt-4">
                                <i class="fa-solid fa-arrow-down mr-1"></i>
                                <?= abs($stats['products_growth']) ?>% this month
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm text-slate-500">
                                    Low Stock
                                </p>
                                <h3 class="text-3xl font-bold text-slate-900 mt-2">
                                    <?= number_format($stats['low_stock']) ?>
                                </h3>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-amber-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-triangle-exclamation text-amber-500 text-2xl"></i>
                            </div>
                        </div>
                        <?php if ($stats['low_stock'] > 0): ?>
                            <a
                                href="products.php?filter=low_stock"
                                title="<?= htmlspecialchars(implode("\n", $stats['low_stock_list'])) ?>"
                                class="block text-sm text-amber-600 mt-4"
                            >
                                <i class="fa-solid fa-circle-exclamation mr-1"></i>
                                Needs attention
                            </a>
                        <?php else: ?>
                            <p class="text-sm text-green-600 mt-4">
                                <i class="fa-solid fa-circle-check mr-1"></i>
                                Stock levels are good
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm text-slate-500">
                                    Stock In
                                </p>
                                <h3 class="text-3xl font-bold text-slate-900 mt-2">
                                    <?= htmlspecialchars(formatQty($stats['stock_in']), ENT_QUOTES, 'UTF-8') ?>
                                </h3>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-green-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-arrow-down text-green-600 text-2xl"></i>
                            </div>
                        </div>
                        <p class="text-sm text-green-600 mt-4">
                            <i class="fa-solid fa-circle-check mr-1"></i>
                            This month
                        </p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm text-slate-500">
                                    Stock Out
                                </p>
                                <h3 class="text-3xl font-bold text-slate-900 mt-2">
                                    <?= htmlspecialchars(formatQty($stats['stock_out']), ENT_QUOTES, 'UTF-8') ?>
                                </h3>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-red-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-arrow-up text-red-600 text-2xl"></i>
                            </div>
                        </div>
                        <p class="text-sm text-red-600 mt-4">
                            <i class="fa-solid fa-circle-check mr-1"></i>
                            This month
                        </p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm text-slate-500">
                                    Inventory Value
                                </p>
                                <h3 class="text-3xl font-bold text-slate-900 mt-2">
                                    <?= peso($stats['inventory_value']) ?>
                                </h3>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-emerald-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-peso-sign text-emerald-600 text-2xl"></i>
                            </div>
                        </div>
                        <p class="text-sm text-emerald-600 mt-4">
                            <i class="fa-solid fa-coins mr-1"></i>
                            Stock value at cost price
                        </p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm text-slate-500">
                                    Total Items
                                </p>
                                <h3 class="text-3xl font-bold text-slate-900 mt-2">
                                    <?= htmlspecialchars(formatQty($stats['total_items']), ENT_QUOTES, 'UTF-8') ?>
                                </h3>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-purple-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-cubes text-purple-600 text-2xl"></i>
                            </div>
                        </div>
                        <p class="text-sm text-purple-600 mt-4">
                            <i class="fa-solid fa-boxes-stacked mr-1"></i>
                            Total items in stock
                        </p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm text-slate-500">
                                    Out of Stock
                                </p>
                                <h3 class="text-3xl font-bold text-slate-900 mt-2">
                                    <?= number_format($stats['out_of_stock']) ?>
                                </h3>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-red-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-box-open text-red-600 text-2xl"></i>
                            </div>
                        </div>
                        <p class="text-sm text-red-600 mt-4">
                            <i class="fa-solid fa-circle-exclamation mr-1"></i>
                            Products with no stock left
                        </p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm text-slate-500">
                                    Suppliers
                                </p>
                                <h3 class="text-3xl font-bold text-slate-900 mt-2">
                                    <?= number_format($stats['total_suppliers']) ?>
                                </h3>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-blue-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-truck-moving text-blue-600 text-2xl"></i>
                            </div>
                        </div>
                        <p class="text-sm text-blue-600 mt-4">
                            <i class="fa-solid fa-address-book mr-1"></i>
                            Registered suppliers
                        </p>
                    </div>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm mt-6 lg:mt-7 p-5 sm:p-6">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                        <div>
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 rounded-xl bg-purple-100 flex items-center justify-center flex-shrink-0">
                                    <i class="fa-solid fa-chart-column text-purple-700"></i>
                                </div>
                                <div>
                                    <h2 class="text-xl font-bold text-slate-900">
                                        Stock Movement by Date
                                    </h2>
                                    <p id="chartSubtitle" class="text-sm text-slate-400 mt-1">
                                        Loading...
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-5 sm:gap-6 overflow-x-auto">
                            <button data-range="hourly" class="text-xs font-semibold text-slate-500 whitespace-nowrap">
                                Hourly
                            </button>
                            <button data-range="daily" class="text-xs font-semibold text-purple-600 border-b-2 border-purple-500 pb-3 whitespace-nowrap">
                                Daily
                            </button>
                            <button data-range="monthly" class="text-xs font-semibold text-slate-500 whitespace-nowrap">
                                Monthly
                            </button>
                            <button class="text-slate-400 text-lg flex-shrink-0">
                                <i class="fa-solid fa-ellipsis"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mt-8 flex">
                        <div class="w-12 flex-shrink-0">
                            <div id="chartYAxis" class="h-64 flex flex-col justify-between text-xs text-slate-400">
                                <span>&nbsp;</span>
                            </div>
                        </div>
                        <div class="flex-1 overflow-x-auto">
                            <div id="chartInner" class="min-w-[900px]">
                                <div class="relative h-64 border-b border-slate-200">
                                    <div class="absolute inset-0 flex flex-col justify-between pointer-events-none">
                                        <div class="border-t border-slate-100"></div>
                                        <div class="border-t border-slate-100"></div>
                                        <div class="border-t border-slate-100"></div>
                                        <div class="border-t border-slate-100"></div>
                                        <div class="border-t border-slate-100"></div>
                                        <div class="border-t border-slate-100"></div>
                                    </div>
                                    <div id="chartBars" class="relative z-10 h-full flex items-end justify-between"></div>
                                </div>
                                <div id="chartLabels" class="flex mt-3"></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-center gap-5 sm:gap-6 mt-7 text-sm text-slate-500">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-teal-400" style="background-color:#2dd4bf"></span>
                            <span>
                                Stock In
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-red-300"></span>
                            <span>
                                Stock Out
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script>
    (function () {
        var ENDPOINT = 'controller/get_stock_chart.php';
        var currentRange = 'daily';
        // same classes as the original buttons (so the design stays identical)
        var ACTIVE   = ['text-purple-600', 'border-b-2', 'border-purple-500', 'pb-3'];
        var INACTIVE = ['text-slate-500'];
        var buttons  = document.querySelectorAll('[data-range]');
        var subtitle = document.getElementById('chartSubtitle');
        var yAxis    = document.getElementById('chartYAxis');
        var inner    = document.getElementById('chartInner');
        var bars     = document.getElementById('chartBars');
        var labels   = document.getElementById('chartLabels');
        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }
        function qty(v) {
            return Number(v).toLocaleString('en-US', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 3
            });
        }
        function axis(v) {
            if (v >= 1000000) return (+(v / 1000000).toFixed(1)) + 'M';
            return Number(v).toLocaleString('en-US');
        }
        function height(v, max) {
            if (v <= 0) return 0;
            return Math.max((v / max) * 100, 1);   // tiny values still get a sliver
        }
        function draw(d) {
            subtitle.textContent = d.subtitle;
            // y-axis
            yAxis.innerHTML = '';
            d.y_labels.forEach(function (v) {
                yAxis.appendChild(el('span', '', axis(v)));
            });
            // wider chart when there are many bars (e.g. hourly = 24)
            inner.style.minWidth = Math.max(900, d.labels.length * 64) + 'px';
            // bars + x-axis labels
            bars.innerHTML = '';
            labels.innerHTML = '';
            d.labels.forEach(function (name, i) {
                var group = el('div', 'flex-1 h-full flex items-end justify-center gap-1');
                var cur = el('div', 'w-5 bg-teal-400 rounded-t-lg');
                cur.style.backgroundColor = '#2dd4bf';
                cur.style.height = height(d.stock_in[i], d.y_max) + '%';
                cur.title = name + ' - Stock In: ' + qty(d.stock_in[i]);
                var prev = el('div', 'w-5 bg-red-300 rounded-t-lg');
                prev.style.height = height(d.stock_out[i], d.y_max) + '%';
                prev.title = name + ' - Stock Out: ' + qty(d.stock_out[i]);
                group.appendChild(cur);
                group.appendChild(prev);
                bars.appendChild(group);
                labels.appendChild(el('div', 'flex-1 text-center text-xs text-slate-400', name));
            });
        }
        function setActive(range) {
            buttons.forEach(function (btn) {
                var on = btn.getAttribute('data-range') === range;
                ACTIVE.forEach(function (c) { btn.classList.toggle(c, on); });
                INACTIVE.forEach(function (c) { btn.classList.toggle(c, !on); });
            });
        }
        function load(range) {
            currentRange = range;
            setActive(range);
            fetch(ENDPOINT + '?range=' + encodeURIComponent(range), { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(draw)
                .catch(function () {
                    subtitle.textContent = 'Could not load chart data';
                });
        }
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                load(btn.getAttribute('data-range'));
            });
        });
        load('daily');
        setInterval(function () { load(currentRange); }, 30000);
    })();
    </script>
</body>
</html>