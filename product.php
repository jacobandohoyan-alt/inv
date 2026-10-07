<?php
/*
 |  Add / edit / delete products.
 |  - SKU is generated automatically (never typed) and cannot be changed.
 |  - Category must come from the fixed list in product_helpers.php.
 |  - Supplier is optional.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// only logged-in users may add / edit / delete products
if (empty($_SESSION["user"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/permissions.php";
requirePermissionForm("products.manage");   // Admin only (see permissions.php)

require_once __DIR__ . "/../database/connect.php";
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/product_helpers.php";
require_once __DIR__ . "/stock_helpers.php";   // nextReference(), formatQty()

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../products.php");
    exit;
}

// CSRF: the forms on products.php send a hidden token
if (!csrfValid()) {
    header("Location: ../products.php?error=" . urlencode("Your session expired. Please refresh the page and try again."));
    exit;
}

date_default_timezone_set("Asia/Manila");
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");

// go back to the products page with a message
function back(string $type, string $message): void
{
    header("Location: ../products.php?" . $type . "=" . urlencode($message));
    exit;
}

// saves a row in stock_in so the dashboard's "Stock In" card goes up (quantity may have decimals for kilo)
function logStockIn($conn, $productId, $supplierId, $quantity, $unitCost, $unit)
{
    $userId    = isset($_SESSION["user"]["id"]) ? (int) $_SESSION["user"]["id"] : null;
    $reference = nextReference($conn, "in");
    $remarks   = "Added from the Products page";

    $stmt = $conn->prepare("
        INSERT INTO stock_in (product_id, supplier_id, quantity, unit_cost, unit, reference_no, remarks, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("iiddsssi", $productId, $supplierId, $quantity, $unitCost, $unit, $reference, $remarks, $userId);
    $stmt->execute();
    $stmt->close();
}

// the quantity was lowered by hand on the Products page: keep a Stock Out record so the history adds up
function logStockOut($conn, $productId, $quantity, $unitCost, $unit)
{
    $userId    = isset($_SESSION["user"]["id"]) ? (int) $_SESSION["user"]["id"] : null;
    $reference = nextReference($conn, "out");
    $reason    = "Adjustment";
    $remarks   = "Quantity changed on the Products page";

    $stmt = $conn->prepare("
        INSERT INTO stock_out (product_id, quantity, unit, reason, unit_cost, reference_no, remarks, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("idssdssi", $productId, $quantity, $unit, $reason, $unitCost, $reference, $remarks, $userId);
    $stmt->execute();
    $stmt->close();
}

ensureProductUnitColumn($conn);   // adds the products.unit column the first time

$action = $_POST["action"] ?? "";


/* =========================================================
   CREATE / UPDATE  (they share the same checks)
   ========================================================= */
if ($action === "create" || $action === "update") {

    $id            = (int) ($_POST["id"] ?? 0);
    $name          = trim($_POST["name"] ?? "");
    $category      = trim($_POST["category"] ?? "");
    $unit          = trim($_POST["unit"] ?? "");
    $supplier_id   = ((int) ($_POST["supplier_id"] ?? 0)) > 0 ? (int) $_POST["supplier_id"] : null;
    $cost_price    = (float) ($_POST["cost_price"] ?? 0);   // what we pay for ONE unit of this product
    $stock_qty     = 0.0;
    $reorder_level = 0.0;

    if ($name === "") {
        back("error", "Product name is required");
    }

    // the unit must come from the list in unit_helpers.php
    if (!isset(productUnits()[$unit])) {
        back("error", "Please select a unit");
    }

    if (!is_numeric($_POST["cost_price"] ?? "0") || $cost_price < 0 || $cost_price > 99999999) {
        back("error", "Cost price must be a valid amount (0 or more)");
    }

    // stock and reorder level: whole numbers, except for products counted in kilo (decimals allowed)
    try {
        $stock_qty     = cleanQuantity($_POST["stock_qty"] ?? "0", $unit, true);
        $reorder_level = cleanQuantity($_POST["reorder_level"] ?? "10", $unit, true);
    } catch (InvalidArgumentException $e) {
        back("error", $e->getMessage());
    }

    // the supplier (if any) must exist
    if ($supplier_id !== null) {

        $check = $conn->prepare("SELECT id FROM suppliers WHERE id = ? LIMIT 1");
        $check->bind_param("i", $supplier_id);
        $check->execute();
        $found = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$found) {
            back("error", "Selected supplier does not exist");
        }
    }

    $categories = productCategories();
}


/* =========================================================
   CREATE
   ========================================================= */
if ($action === "create") {

    if (!isset($categories[$category])) {
        back("error", "Please select a category");
    }

    // same product name twice would be confusing
    $dup = $conn->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
    $dup->bind_param("s", $name);
    $dup->execute();
    $nameTaken = $dup->get_result()->num_rows > 0;
    $dup->close();

    if ($nameTaken) {
        back("error", "A product with this name already exists");
    }

    try {

        $conn->begin_transaction();

        $prefix    = $categories[$category];
        $productId = 0;
        $sku       = "";

        // try up to 5 times in case two people add a product at the same moment
        for ($attempt = 0; $attempt < 5 && !$productId; $attempt++) {

            $sku = nextSku($conn, $prefix);

            try {

                $stmt = $conn->prepare("
                    INSERT INTO products
                        (sku, name, category, unit, supplier_id, cost_price, stock_qty, reorder_level)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    "ssssiddd",
                    $sku,
                    $name,
                    $category,
                    $unit,
                    $supplier_id,
                    $cost_price,
                    $stock_qty,
                    $reorder_level
                );

                $stmt->execute();
                $productId = $conn->insert_id;
                $stmt->close();

            } catch (mysqli_sql_exception $e) {

                // 1062 = that SKU was just taken, so try the next number
                if ($e->getCode() != 1062) {
                    throw $e;
                }
            }
        }

        if (!$productId) {
            throw new RuntimeException("Could not generate a SKU");
        }

        // starting stock counts as a Stock In
        if ($stock_qty > 0) {
            logStockIn($conn, $productId, $supplier_id, $stock_qty, $cost_price, $unit);
        }

        $conn->commit();

    } catch (Throwable $e) {

        $conn->rollback();
        error_log("controller/product.php create: " . $e->getMessage());
        back("error", "Unable to add product");
    }

    back("success", "Product added successfully (SKU: " . $sku . ")");
}


/* =========================================================
   UPDATE  (the SKU is never changed)
   ========================================================= */
if ($action === "update") {

    if ($id <= 0) {
        back("error", "Invalid product information");
    }

    $find = $conn->prepare("SELECT stock_qty, category, cost_price FROM products WHERE id = ? LIMIT 1");
    $find->bind_param("i", $id);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();
    $find->close();

    if (!$existing) {
        back("error", "Product not found");
    }

    // old products may have a category that is not in the list: allow them to keep it
    if (!isset($categories[$category]) && $category !== (string) $existing["category"]) {
        back("error", "Please select a valid category");
    }

    $dup = $conn->prepare("SELECT id FROM products WHERE name = ? AND id != ? LIMIT 1");
    $dup->bind_param("si", $name, $id);
    $dup->execute();
    $nameTaken = $dup->get_result()->num_rows > 0;
    $dup->close();

    if ($nameTaken) {
        back("error", "A product with this name already exists");
    }

    try {

        $conn->begin_transaction();

        // read the stock again under a lock, so two people editing at once cannot lose a Stock In / Out record
        $lock = $conn->prepare("SELECT stock_qty FROM products WHERE id = ? FOR UPDATE");
        $lock->bind_param("i", $id);
        $lock->execute();
        $oldStockQty = round((float) $lock->get_result()->fetch_row()[0], 3);
        $lock->close();

        $stmt = $conn->prepare("
            UPDATE products
            SET
                name = ?,
                category = ?,
                unit = ?,
                supplier_id = ?,
                cost_price = ?,
                stock_qty = ?,
                reorder_level = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "sssidddi",
            $name,
            $category,
            $unit,
            $supplier_id,
            $cost_price,
            $stock_qty,
            $reorder_level,
            $id
        );

        $stmt->execute();
        $stmt->close();

        // quantity went UP -> record the extra units as a Stock In
        // quantity went DOWN -> record the removed units as a Stock Out (Adjustment)
        if ($stock_qty > $oldStockQty) {
            logStockIn($conn, $id, $supplier_id, round($stock_qty - $oldStockQty, 3), $cost_price, $unit);
        } elseif ($stock_qty < $oldStockQty) {
            logStockOut($conn, $id, round($oldStockQty - $stock_qty, 3), $cost_price, $unit);
        }

        $conn->commit();

    } catch (Throwable $e) {

        $conn->rollback();
        error_log("controller/product.php update: " . $e->getMessage());
        back("error", "Unable to update product");
    }

    back("success", "Product updated successfully");
}


/* =========================================================
   DELETE
   ========================================================= */
if ($action === "delete") {

    $id = (int) ($_POST["id"] ?? 0);

    if ($id <= 0) {
        back("error", "Invalid product");
    }

    $check = $conn->prepare("SELECT id FROM products WHERE id = ? LIMIT 1");
    $check->bind_param("i", $id);
    $check->execute();
    $found = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$found) {
        back("error", "Product not found");
    }

    try {

        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

    } catch (mysqli_sql_exception $e) {

        if ($e->getCode() == 1451) {
            back("error", "This product cannot be deleted because it is already used in stock records");
        }

        back("error", "Unable to delete product");
    }

    back("success", "Product deleted successfully");
}

header("Location: ../products.php");
exit;