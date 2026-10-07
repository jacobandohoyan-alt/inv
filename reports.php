<?php
include('controller/authenticator.php');
requirePermission('reports.view');   // role check: staff typing this URL are sent to access_denied.php
include('database/connect.php');
require_once __DIR__ . '/controller/unit_helpers.php';   // formatQty()

date_default_timezone_set('Asia/Manila');
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function peso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function validDate($value, $fallback): string
{
    $value = is_string($value) ? $value : '';
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : $fallback;
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$from = validDate($_GET['from'] ?? $monthStart, $monthStart);
$to   = validDate($_GET['to'] ?? $today, $today);

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$fromDT = $from . ' 00:00:00';
$toDT   = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));

/* =========================================================
   INVENTORY PERFORMANCE
   ========================================================= */
$totalProducts = (int)$conn->query("SELECT COUNT(*) FROM products")->fetch_row()[0];
$totalItems = (float)$conn->query("SELECT COALESCE(SUM(stock_qty),0) FROM products")->fetch_row()[0];
$lowStock = (int)$conn->query("SELECT COUNT(*) FROM products WHERE stock_qty <= reorder_level")->fetch_row()[0];
$inventoryValue = (float)$conn->query("SELECT COALESCE(SUM(stock_qty * cost_price),0) FROM products")->fetch_row()[0];
$totalSuppliers = (int)$conn->query("SELECT COUNT(*) FROM suppliers")->fetch_row()[0];
$totalUsers = (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];

$stmt = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date <= ?");
$stmt->bind_param('ss', $from, $to);
$stmt->execute();
$stmt->bind_result($expenseCount, $expenseTotal);
$stmt->fetch();
$stmt->close();
$expenseCount = (int)$expenseCount;
$expenseTotal = (float)$expenseTotal;

// stock value moved in / out during the selected period (quantity x cost price at the time)
$stmt = $conn->prepare("SELECT COALESCE(SUM(quantity),0), COALESCE(SUM(quantity * unit_cost),0) FROM stock_in WHERE received_at >= ? AND received_at < ?");
$stmt->bind_param('ss', $fromDT, $toDT);
$stmt->execute();
$stmt->bind_result($stockInQty, $stockInValue);
$stmt->fetch();
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(quantity),0), COALESCE(SUM(quantity * unit_cost),0) FROM stock_out WHERE released_at >= ? AND released_at < ?");
$stmt->bind_param('ss', $fromDT, $toDT);
$stmt->execute();
$stmt->bind_result($stockOutQty, $stockOutValue);
$stmt->fetch();
$stmt->close();

/* =========================================================
   PRODUCT REPORT
   ========================================================= */
$products = [];
$res = $conn->query("SELECT p.id,p.sku,p.name,p.category,p.cost_price,p.unit,p.stock_qty,p.reorder_level,s.name AS supplier_name FROM products p LEFT JOIN suppliers s ON s.id=p.supplier_id ORDER BY p.name ASC");
while ($row = $res->fetch_assoc()) $products[] = $row;

/* =========================================================
   SUPPLIER REPORT
   ========================================================= */
$suppliers = [];
$res = $conn->query("SELECT s.id,s.name,s.contact_person,s.phone,s.status,COUNT(DISTINCT p.id) AS product_count,COUNT(DISTINCT e.id) AS expense_count,COALESCE(SUM(e.amount),0) AS expense_total FROM suppliers s LEFT JOIN products p ON p.supplier_id=s.id LEFT JOIN expenses e ON e.supplier_id=s.id GROUP BY s.id ORDER BY s.name ASC");
while ($row = $res->fetch_assoc()) $suppliers[] = $row;

/* =========================================================
   STOCK MOVEMENT REPORT
   stock_in and stock_out records carry the user who recorded them.
   ========================================================= */
$stockIn = [];
$stmt=$conn->prepare("SELECT si.received_at,si.quantity,si.unit_cost,p.sku,p.name,s.name AS supplier_name,COALESCE(u.username,'Unknown') AS in_charge FROM stock_in si LEFT JOIN products p ON p.id=si.product_id LEFT JOIN suppliers s ON s.id=si.supplier_id LEFT JOIN users u ON u.id=si.user_id WHERE si.received_at >= ? AND si.received_at < ? ORDER BY si.received_at DESC");
$stmt->bind_param('ss',$fromDT,$toDT);
$stmt->execute(); $res=$stmt->get_result();
while($row=$res->fetch_assoc()) $stockIn[]=$row;
$stmt->close();

$stockOut=[];
// stock taken out by hand on the Stock In / Out page (used, damaged, expired ...)
$stmt=$conn->prepare("SELECT so.released_at AS created_at,so.quantity,so.unit_cost AS unit_price,so.reason,so.reference_no,p.sku,p.name,COALESCE(u.username,'Unknown') AS in_charge FROM stock_out so LEFT JOIN products p ON p.id=so.product_id LEFT JOIN users u ON u.id=so.user_id WHERE so.released_at >= ? AND so.released_at < ? ORDER BY so.released_at DESC");
$stmt->bind_param('ss',$fromDT,$toDT);
$stmt->execute(); $res=$stmt->get_result();
while($row=$res->fetch_assoc()){ $row['ref_label']=trim(($row['reference_no'] ?: 'Stock Out').' - '.$row['reason']); $stockOut[]=$row; }
$stmt->close();
usort($stockOut,function($a,$b){ return strcmp($b['created_at'],$a['created_at']); });

/* =========================================================
   EXPENSE REPORT
   ========================================================= */
$expenses=[];
$stmt=$conn->prepare("SELECT e.expense_date,e.category,e.description,e.amount,e.payment_method,s.name AS supplier_name,COALESCE(u.username,'Unknown') AS in_charge FROM expenses e LEFT JOIN suppliers s ON s.id=e.supplier_id LEFT JOIN users u ON u.id=e.recorded_by WHERE e.expense_date >= ? AND e.expense_date <= ? ORDER BY e.expense_date DESC,e.id DESC");
$stmt->bind_param('ss',$from,$to);
$stmt->execute(); $res=$stmt->get_result();
while($row=$res->fetch_assoc()) $expenses[]=$row;
$stmt->close();

/* =========================================================
   PERSON-IN-CHARGE SUMMARY
   Combines stock-in, stock-out and expenses recorded during the period.
   ========================================================= */
$people=[];
foreach($stockIn as $r){
    $name=$r['in_charge'] ?: 'Unknown';
    if(!isset($people[$name])) $people[$name]=['stock_in'=>0,'stock_out'=>0,'expenses'=>0,'expense_amount'=>0];
    $people[$name]['stock_in']+=(float)$r['quantity'];
}
foreach($stockOut as $r){
    $name=$r['in_charge'] ?: 'Unknown';
    if(!isset($people[$name])) $people[$name]=['stock_in'=>0,'stock_out'=>0,'expenses'=>0,'expense_amount'=>0];
    $people[$name]['stock_out']+=(float)$r['quantity'];
}
foreach($expenses as $r){
    $name=$r['in_charge'] ?: 'Unknown';
    if(!isset($people[$name])) $people[$name]=['stock_in'=>0,'stock_out'=>0,'expenses'=>0,'expense_amount'=>0];
    $people[$name]['expenses']++;
    $people[$name]['expense_amount']+=(float)$r['amount'];
}
ksort($people,SORT_NATURAL|SORT_FLAG_CASE);

/* =========================================================
   CSV EXPORT
   ========================================================= */
if(($_GET['export'] ?? '') === 'csv'){
    $type=$_GET['type'] ?? 'performance';
    $filename='OMG_'.$type.'_'.$from.'_to_'.$to.'.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out=fopen('php://output','w');

    if($type==='products'){
        fputcsv($out,['SKU','Product','Category','Supplier','Stock','Unit','Reorder Level','Cost Price','Inventory Value']);
        foreach($products as $r) fputcsv($out,[$r['sku'],$r['name'],$r['category'],$r['supplier_name'],formatQty($r['stock_qty'],false),unitLabel($r['unit']),formatQty($r['reorder_level'],false),$r['cost_price'],$r['stock_qty']*$r['cost_price']]);
    } elseif($type==='suppliers'){
        fputcsv($out,['Supplier','Contact Person','Phone','Status','Products','Expense Records','Expense Total']);
        foreach($suppliers as $r) fputcsv($out,[$r['name'],$r['contact_person'],$r['phone'],$r['status'],$r['product_count'],$r['expense_count'],$r['expense_total']]);
    } elseif($type==='stock'){
        fputcsv($out,['Type','Date/Time','SKU','Product','Quantity','Unit Cost','Supplier/Reference','Person in Charge']);
        foreach($stockIn as $r) fputcsv($out,['STOCK IN',$r['received_at'],$r['sku'],$r['name'],formatQty($r['quantity'],false),$r['unit_cost'],$r['supplier_name'] ?? 'No supplier',$r['in_charge']]);
        foreach($stockOut as $r) fputcsv($out,['STOCK OUT',$r['created_at'],$r['sku'],$r['name'],formatQty($r['quantity'],false),$r['unit_price'],$r['ref_label'],$r['in_charge']]);
    } elseif($type==='expenses'){
        fputcsv($out,['Date','Category','Description','Amount','Payment Method','Supplier','Person in Charge']);
        foreach($expenses as $r) fputcsv($out,[$r['expense_date'],$r['category'],$r['description'],$r['amount'],$r['payment_method'],$r['supplier_name'],$r['in_charge']]);
    } elseif($type==='people'){
        fputcsv($out,['Person in Charge','Stock In Units','Stock Out Units','Expense Records','Expense Amount']);
        foreach($people as $name=>$r) fputcsv($out,[$name,$r['stock_in'],$r['stock_out'],$r['expenses'],$r['expense_amount']]);
    } else {
        fputcsv($out,['Metric','Value']);
        fputcsv($out,['Period',$from.' to '.$to]);
        fputcsv($out,['Total Products',$totalProducts]);
        fputcsv($out,['Total Items',$totalItems]);
        fputcsv($out,['Low Stock',$lowStock]);
        fputcsv($out,['Inventory Value',$inventoryValue]);
        fputcsv($out,['Suppliers',$totalSuppliers]);
        fputcsv($out,['Users',$totalUsers]);
        fputcsv($out,['Stock In (units)',$stockInQty]);
        fputcsv($out,['Stock In (value at cost)',$stockInValue]);
        fputcsv($out,['Stock Out (units)',$stockOutQty]);
        fputcsv($out,['Stock Out (value at cost)',$stockOutValue]);
        fputcsv($out,['Expenses',$expenseTotal]);
    }
    fclose($out); exit;
}

$activePage='reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports</title>
<link rel="stylesheet" href="tailwind.min.css">
<script src="https://kit.fontawesome.com/7fa31b9a7a.js" crossorigin="anonymous"></script>
<style>
@media print{aside,.no-print{display:none!important}main{width:100%!important}.print-card{box-shadow:none!important;border:0!important}}
.report-table{min-width:900px}.scroll{overflow-x:auto}
</style>
</head>
<body class="bg-purple-200">
<div class="grid grid-cols-1 lg:grid-cols-6 min-h-screen">
<?php 
$activePage = "reports";
include 'partials/sidebar.php';

?>
<main class="lg:col-span-5 bg-purple-200 min-h-screen">
    <div class="bg-purple-700 p-5 shadow-lg">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div><p class="text-purple-200 text-sm">Business Management</p><h1 class="text-2xl font-bold text-white">Reports</h1></div>
            <div class="bg-purple-600 rounded-xl px-4 py-3 text-white"><p class="text-xs text-purple-200">Report generated by</p><p class="font-bold"><?=h(strtoupper($_SESSION['user']['username'] ?? 'USER'))?></p></div>
        </div>
    </div>

    <div class="p-5 sm:p-6 lg:p-7">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-6 no-print">
            <form method="get" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div><label class="block text-sm font-semibold text-slate-700 mb-2">From</label><input type="date" name="from" value="<?=h($from)?>" class="w-full border border-slate-300 rounded-xl p-3"></div>
                <div><label class="block text-sm font-semibold text-slate-700 mb-2">To</label><input type="date" name="to" value="<?=h($to)?>" class="w-full border border-slate-300 rounded-xl p-3"></div>
                <div class="flex gap-2"><button class="flex-1 bg-purple-700 hover:bg-purple-800 text-white rounded-xl p-3 font-semibold"><i class="fa-solid fa-filter mr-2"></i>Generate Report</button><button type="button" onclick="window.print()" class="px-5 bg-slate-100 rounded-xl"><i class="fa-solid fa-print"></i></button></div>
            </form>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-4 mb-6">
            <?php foreach([
                ['Products',$totalProducts,'fa-box','violet'],['Items',formatQty($totalItems),'fa-cubes','purple'],['Low Stock',$lowStock,'fa-triangle-exclamation','amber'],['Stock In',formatQty($stockInQty),'fa-arrow-down','green'],['Stock Out',formatQty($stockOutQty),'fa-arrow-up','rose'],['Expenses',peso($expenseTotal),'fa-money-bill-wave','blue']
            ] as $c): ?>
            <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm"><div class="text-xs text-slate-500"><?=h($c[0])?></div><div class="text-xl font-bold text-slate-900 mt-2"><?=h($c[1])?></div><i class="fa-solid <?=$c[2]?> text-<?=$c[3]?>-500 mt-3"></i></div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-2xl border p-5"><p class="text-sm text-slate-500">Stock In Value</p><p class="text-2xl font-bold mt-2"><?=peso($stockInValue)?></p><p class="text-xs text-slate-500 mt-2">Received in the period, at cost price</p></div>
            <div class="bg-white rounded-2xl border p-5"><p class="text-sm text-slate-500">Stock Out Value</p><p class="text-2xl font-bold mt-2"><?=peso($stockOutValue)?></p><p class="text-xs text-slate-500 mt-2">Released in the period, at cost price</p></div>
            <div class="bg-white rounded-2xl border p-5"><p class="text-sm text-slate-500">Inventory Value</p><p class="text-2xl font-bold mt-2"><?=peso($inventoryValue)?></p><p class="text-xs text-slate-500 mt-2">Current stock at cost</p></div>
        </div>

        <?php
        $sections=[
            ['Inventory Summary','performance','A summary of the selected period.'],
            ['Product Report','products','Products, stock levels and suppliers.'],
            ['Supplier Report','suppliers','Supplier status, products and related expenses.'],
            ['Stock Movement Report','stock','Stock-in and stock-out movements.'],
            ['Expense Report','expenses','Business expenses and the person who recorded them.'],
            ['User / Person-in-Charge Report','people','Shows who handled stock-in, stock-out and expenses during the selected period.']
        ];
        ?>

        <?php foreach($sections as $idx=>$sec): ?>
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-6 print-card">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
                <div><h2 class="text-xl font-bold text-slate-900"><?=h($sec[0])?></h2><p class="text-sm text-slate-500"><?=h($sec[2])?></p></div>
                <a class="no-print inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-purple-50 text-purple-700 font-semibold" href="?from=<?=h($from)?>&to=<?=h($to)?>&export=<?=h($sec[1])?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
            </div>

            <?php if($sec[1]==='performance'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div class="border rounded-xl p-4">Period <strong class="float-right"><?=h($from)?> to <?=h($to)?></strong></div>
                                <div class="border rounded-xl p-4">Total Suppliers <strong class="float-right"><?=$totalSuppliers?></strong></div>
                <div class="border rounded-xl p-4">Total Users <strong class="float-right"><?=$totalUsers?></strong></div>
                <div class="border rounded-xl p-4">Expense Records <strong class="float-right"><?=$expenseCount?></strong></div>
            </div>
            <?php elseif($sec[1]==='products'): ?>
            <div class="scroll"><table class="report-table w-full text-sm"><thead><tr class="border-b text-left text-slate-500"><th class="p-3">SKU</th><th class="p-3">Product</th><th class="p-3">Category</th><th class="p-3">Supplier</th><th class="p-3">Stock</th><th class="p-3">Reorder</th><th class="p-3">Cost Price</th><th class="p-3">Stock Value</th></tr></thead><tbody><?php foreach($products as $r): ?><tr class="border-b"><td class="p-3"><?=h($r['sku'])?></td><td class="p-3 font-semibold"><?=h($r['name'])?></td><td class="p-3"><?=h($r['category'])?></td><td class="p-3"><?=h($r['supplier_name']??'—')?></td><td class="p-3"><?=h(formatQty($r['stock_qty']))?> <span class="text-xs text-slate-400"><?=h(unitLabel($r['unit']))?></span></td><td class="p-3"><?=h(formatQty($r['reorder_level']))?></td><td class="p-3"><?=peso($r['cost_price'])?></td><td class="p-3"><?=peso($r['stock_qty']*$r['cost_price'])?></td></tr><?php endforeach; ?></tbody></table></div>
            <?php elseif($sec[1]==='suppliers'): ?>
            <div class="scroll"><table class="report-table w-full text-sm"><thead><tr class="border-b text-left text-slate-500"><th class="p-3">Supplier</th><th class="p-3">Contact</th><th class="p-3">Phone</th><th class="p-3">Status</th><th class="p-3">Products</th><th class="p-3">Expenses</th><th class="p-3">Expense Total</th></tr></thead><tbody><?php foreach($suppliers as $r): ?><tr class="border-b"><td class="p-3 font-semibold"><?=h($r['name'])?></td><td class="p-3"><?=h($r['contact_person'])?></td><td class="p-3"><?=h($r['phone'])?></td><td class="p-3"><?=h(ucfirst($r['status']))?></td><td class="p-3"><?=$r['product_count']?></td><td class="p-3"><?=$r['expense_count']?></td><td class="p-3"><?=peso($r['expense_total'])?></td></tr><?php endforeach; ?></tbody></table></div>
            <?php elseif($sec[1]==='stock'): ?>
            <div class="scroll"><table class="report-table w-full text-sm"><thead><tr class="border-b text-left text-slate-500"><th class="p-3">Type</th><th class="p-3">Date/Time</th><th class="p-3">Product</th><th class="p-3">Qty</th><th class="p-3">Value</th><th class="p-3">Supplier/Reference</th><th class="p-3">Person in Charge</th></tr></thead><tbody><?php foreach($stockIn as $r): ?><tr class="border-b"><td class="p-3 text-green-700 font-semibold">STOCK IN</td><td class="p-3"><?=h($r['received_at'])?></td><td class="p-3"><?=h($r['name'])?></td><td class="p-3">+<?=h(formatQty($r['quantity']))?></td><td class="p-3"><?=peso($r['unit_cost']*$r['quantity'])?></td><td class="p-3"><?=h($r['supplier_name']??'—')?></td><td class="p-3 font-semibold"><?=h($r['in_charge'])?></td></tr><?php endforeach; ?><?php foreach($stockOut as $r): ?><tr class="border-b"><td class="p-3 text-red-700 font-semibold">STOCK OUT</td><td class="p-3"><?=h($r['created_at'])?></td><td class="p-3"><?=h($r['name'])?></td><td class="p-3">-<?=h(formatQty($r['quantity']))?></td><td class="p-3"><?=peso($r['unit_price']*$r['quantity'])?></td><td class="p-3"><?=h($r['ref_label'])?></td><td class="p-3 font-semibold"><?=h($r['in_charge'])?></td></tr><?php endforeach; ?><?php if(!$stockIn&&!$stockOut): ?><tr><td colspan="7" class="p-5 text-center text-slate-500">No stock movement in this period.</td></tr><?php endif; ?></tbody></table></div>
            <?php elseif($sec[1]==='expenses'): ?>
            <div class="scroll"><table class="report-table w-full text-sm"><thead><tr class="border-b text-left text-slate-500"><th class="p-3">Date</th><th class="p-3">Category</th><th class="p-3">Description</th><th class="p-3">Amount</th><th class="p-3">Payment</th><th class="p-3">Supplier</th><th class="p-3">Person in Charge</th></tr></thead><tbody><?php foreach($expenses as $r): ?><tr class="border-b"><td class="p-3"><?=h($r['expense_date'])?></td><td class="p-3"><?=h($r['category'])?></td><td class="p-3"><?=h($r['description'])?></td><td class="p-3"><?=peso($r['amount'])?></td><td class="p-3"><?=h($r['payment_method'])?></td><td class="p-3"><?=h($r['supplier_name']??'—')?></td><td class="p-3 font-semibold"><?=h($r['in_charge'])?></td></tr><?php endforeach; ?><?php if(!$expenses): ?><tr><td colspan="7" class="p-5 text-center text-slate-500">No expenses in this period.</td></tr><?php endif; ?></tbody></table></div>
            <?php else: ?>
            <div class="scroll"><table class="report-table w-full text-sm"><thead><tr class="border-b text-left text-slate-500"><th class="p-3">Person in Charge</th><th class="p-3">Stock In Units</th><th class="p-3">Stock Out Units</th><th class="p-3">Expense Records</th><th class="p-3">Expense Amount</th></tr></thead><tbody><?php foreach($people as $name=>$r): ?><tr class="border-b"><td class="p-3 font-semibold"><?=h($name)?></td><td class="p-3"><?=h(formatQty($r['stock_in']))?></td><td class="p-3"><?=h(formatQty($r['stock_out']))?></td><td class="p-3"><?=$r['expenses']?></td><td class="p-3"><?=peso($r['expense_amount'])?></td></tr><?php endforeach; ?><?php if(!$people): ?><tr><td colspan="5" class="p-5 text-center text-slate-500">No recorded activity in this period.</td></tr><?php endif; ?></tbody></table></div>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>

        <p class="text-center text-sm text-slate-500 pb-5">Report generated <?=h(date('F j, Y g:i A'))?> by <?=h(strtoupper($_SESSION['user']['username'] ?? 'USER'))?></p>
    </div>
</main>
</div>
</body>
</html>
