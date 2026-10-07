<?php
/*
 |  Stock In / Stock Out API used by stock.php (JSON).
 |
 |  { "type": "in"|"out", "action": "batch"|"update"|"delete", ... }
 |
 |  batch  : several products in ONE entry (one shared reference number)
 |           { date, supplier_id (in, optional) | reason (out), remarks, items: [{ product_id, quantity, unit, unit_cost }] }
 |  update : change one record      { id, product_id, quantity, unit, date, supplier_id | reason, unit_cost, remarks }
 |  delete : remove one record     { id }   (the stock is adjusted back)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (empty($_SESSION["user"])) {
    respond(["error" => "Not logged in"], 401);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(["error" => "Invalid request"], 405);
}

// CSRF: the page sends its token in the X-CSRF-Token header
$sentToken = (string) ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");

if ($sentToken === "" || empty($_SESSION["csrf"]) || !hash_equals($_SESSION["csrf"], $sentToken)) {
    respond(["error" => "Your session expired. Please refresh the page and try again."], 403);
}

require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/../database/connect.php";
require_once __DIR__ . "/stock_helpers.php";

date_default_timezone_set("Asia/Manila");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");
ensureProductUnitColumn($conn);   // adds products.unit the first time

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    respond(["error" => "Invalid request"], 400);
}

$type   = (($input["type"] ?? "") === "out") ? "out" : "in";
$label  = $type === "in" ? "Stock In" : "Stock Out";
$action = $input["action"] ?? "";
$id     = (int) ($input["id"] ?? 0);

// role check: recording stock is for staff and admin; editing / deleting old records is Admin only
requirePermissionApi($action === "batch" ? "stock.record" : "stock.edit");

try {

    $conn->begin_transaction();

    /* ---------- several products in one entry ---------- */
    if ($action === "batch") {

        $items = $input["items"] ?? null;

        if (!is_array($items) || count($items) === 0) {
            throw new StockException("Please add at least one product.");
        }

        if (count($items) > 100) {
            throw new StockException("Too many products in one entry (maximum 100).");
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new StockException("One of the items is not valid.");
            }
        }

        // always lock products in the same order (prevents two users blocking each other)
        usort($items, function ($a, $b) {
            return (int) ($a["product_id"] ?? 0) <=> (int) ($b["product_id"] ?? 0);
        });

        $reference = nextReference($conn, $type);
        $userId    = currentUserId();

        foreach ($items as $item) {

            $data = cleanStock($conn, [
                "product_id"  => $item["product_id"] ?? 0,
                "quantity"    => $item["quantity"] ?? 0,
                "unit"        => $item["unit"] ?? "",
                "unit_cost"   => $item["unit_cost"] ?? null,
                "date"        => $input["date"] ?? "",
                "remarks"     => $input["remarks"] ?? "",
                "supplier_id" => $input["supplier_id"] ?? 0,
                "reason"      => $input["reason"] ?? "",
            ], $type);

            if ($type === "in") {
                createStockIn($conn, $data, $reference, $userId);
            } else {
                createStockOut($conn, $data, $reference, $userId);
            }
        }

        $conn->commit();

        $count = count($items);

        respond([
            "success" => true,
            "message" => $label . " " . $reference . " saved (" . $count . " product" . ($count === 1 ? "" : "s") . ").",
        ]);
    }

    /* ---------- change one record ---------- */
    if ($action === "update") {

        if ($id <= 0) {
            throw new StockException("Invalid record.");
        }

        $data  = cleanStock($conn, $input, $type);
        $stock = $type === "in" ? updateStockIn($conn, $id, $data) : updateStockOut($conn, $id, $data);

        $conn->commit();

        respond(["success" => true, "message" => $label . " record updated. Current stock: " . formatQty($stock) . "."]);
    }

    /* ---------- delete one record ---------- */
    if ($action === "delete") {

        if ($id <= 0) {
            throw new StockException("Invalid record.");
        }

        $type === "in" ? deleteStockIn($conn, $id) : deleteStockOut($conn, $id);

        $conn->commit();

        respond(["success" => true, "message" => $label . " record deleted and the stock was adjusted."]);
    }

    $conn->rollback();
    respond(["error" => "Unknown action."], 400);

} catch (StockException $e) {

    $conn->rollback();
    respond(["error" => $e->getMessage()], 409);

} catch (Throwable $e) {

    $conn->rollback();
    error_log("controller/stock.php: " . $e->getMessage());   // the real reason goes to the PHP error log
    respond(["error" => "Could not save. Please try again."], 500);
}
