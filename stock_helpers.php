<?php
/*
 |  Shared stock logic for Stock In and Stock Out (stock.php, controller/stock.php, controller/product.php).
 |  Every function here must be called INSIDE a database transaction
 |  (begin_transaction ... commit) by the controller.
 */

// a message that is safe to show to the user
if (!class_exists("StockException")) {
    class StockException extends Exception
    {
    }
}

require_once __DIR__ . "/unit_helpers.php";

// the units are the same list as on the Products page (see unit_helpers.php)
function stockUnits(): array
{
    return array_keys(productUnits());
}

function stockOutReasons(): array
{
    return ["Used", "Damaged", "Expired", "Adjustment", "Other"];
}

function currentUserId(): ?int
{
    return isset($_SESSION["user"]["id"]) ? (int) $_SESSION["user"]["id"] : null;
}

if (!function_exists("runQuery")) {
    function runQuery($conn, $sql, $types, $params)
    {
        $stmt = $conn->prepare($sql);

        if ($types !== "") {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        return $stmt->get_result();
    }
}

// next reference number, e.g. SI-2026-0007 (stock in) or SO-2026-0003 (stock out)
function nextReference(mysqli $conn, string $type): string
{
    $prefix = $type === "in" ? "SI" : "SO";
    $table  = $type === "in" ? "stock_in" : "stock_out";
    $year   = date("Y");
    $like   = $prefix . "-" . $year . "-%";

    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference_no, '-', -1) AS UNSIGNED)), 0)
        FROM $table
        WHERE reference_no LIKE ?
    ");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $max = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    return sprintf("%s-%s-%04d", $prefix, $year, $max + 1);
}

// add (+) or remove (-) units of a product. Never lets the stock go below zero.
// Quantities can have decimals (kilo), so everything is rounded to 3 decimals.
function changeStock(mysqli $conn, int $productId, float $delta): float
{
    $stmt = $conn->prepare("SELECT name, stock_qty FROM products WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        throw new StockException("The selected product no longer exists.");
    }

    $current  = round((float) $product["stock_qty"], 3);
    $newStock = round($current + $delta, 3);

    if ($newStock < 0) {
        throw new StockException(
            'Not enough stock of "' . $product["name"] . '" (only ' . formatQty($current) . " available)."
        );
    }

    $update = $conn->prepare("UPDATE products SET stock_qty = ? WHERE id = ?");
    $update->bind_param("di", $newStock, $productId);
    $update->execute();
    $update->close();

    return $newStock;
}

function productCost(mysqli $conn, int $productId): float
{
    $stmt = $conn->prepare("SELECT cost_price FROM products WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $row ? (float) $row[0] : 0.0;
}

// the unit of a product, as set on the Products page (pcs if the product is not found).
// Stock In / Stock Out always use this unit, they never guess it.
function productUnit(mysqli $conn, int $productId): string
{
    ensureProductUnitColumn($conn);

    $stmt = $conn->prepare("SELECT unit FROM products WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return ($row && $row[0] !== null && $row[0] !== "") ? (string) $row[0] : "pcs";
}

function fetchRecord(mysqli $conn, string $table, int $id): ?array
{
    // $table is only ever "stock_in" or "stock_out" (set in this file)
    $stmt = $conn->prepare("SELECT * FROM $table WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// checks what the user typed and returns clean values
function cleanStock(mysqli $conn, array $in, string $type): array
{
    $productId = (int) ($in["product_id"] ?? 0);
    $date      = (string) ($in["date"] ?? "");
    $remarks   = trim((string) ($in["remarks"] ?? ""));

    if ($productId <= 0) {
        throw new StockException("Please choose a product from the list.");
    }

    // the unit always comes from the product (Products page), never from the form
    $unit = productUnit($conn, $productId);

    // kilo products may have decimals (2.5 kilo); every other unit must be a whole number
    try {
        $quantity = cleanQuantity($in["quantity"] ?? "", $unit);
    } catch (InvalidArgumentException $e) {
        throw new StockException($e->getMessage());
    }

    $parsed = DateTime::createFromFormat("Y-m-d", $date);

    if (!$parsed || $parsed->format("Y-m-d") !== $date) {
        throw new StockException("Please enter a valid date.");
    }

    if ($date > date("Y-m-d")) {
        throw new StockException("The date cannot be in the future.");
    }

    if (mb_strlen($remarks) > 255) {
        throw new StockException("Remarks are too long (maximum 255 characters).");
    }

    $clean = [
        "product_id" => $productId,
        "quantity"   => $quantity,
        "unit"       => $unit,
        "when"       => $date . " " . date("H:i:s"),
        "remarks"    => $remarks === "" ? null : $remarks,
    ];

    if ($type === "in") {

        $unitCost = isset($in["unit_cost"]) && is_numeric($in["unit_cost"]) ? round((float) $in["unit_cost"], 2) : -1;

        if ($unitCost < 0 || $unitCost > 99999999) {
            throw new StockException("Please enter a valid unit cost.");
        }

        // the supplier is OPTIONAL: Stock In is saved even when no supplier is selected
        $supplierId = (int) ($in["supplier_id"] ?? 0);

        if ($supplierId <= 0) {
            $supplierId = null;
        } else {
            $check = $conn->prepare("SELECT id FROM suppliers WHERE id = ? LIMIT 1");
            $check->bind_param("i", $supplierId);
            $check->execute();
            $found = $check->get_result()->num_rows > 0;
            $check->close();

            if (!$found) {
                throw new StockException("The selected supplier does not exist.");
            }
        }

        $clean["unit_cost"]   = $unitCost;
        $clean["supplier_id"] = $supplierId;

    } else {

        $reason = (string) ($in["reason"] ?? "");

        if (!in_array($reason, stockOutReasons(), true)) {
            throw new StockException("Please select a reason.");
        }

        $clean["reason"] = $reason;

        // cost of the removed units (batch entry sends it, otherwise the product's cost is used)
        if (isset($in["unit_cost"]) && is_numeric($in["unit_cost"]) && (float) $in["unit_cost"] >= 0) {
            $clean["unit_cost"] = round((float) $in["unit_cost"], 2);
        }
    }

    return $clean;
}


/* ===================== STOCK IN ===================== */

function createStockIn(mysqli $conn, array $d, string $reference, ?int $userId): array
{
    $stock = changeStock($conn, $d["product_id"], $d["quantity"]);

    $stmt = $conn->prepare("
        INSERT INTO stock_in (product_id, supplier_id, quantity, unit_cost, unit, reference_no, remarks, received_at, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "iiddssssi",
        $d["product_id"],
        $d["supplier_id"],
        $d["quantity"],
        $d["unit_cost"],
        $d["unit"],
        $reference,
        $d["remarks"],
        $d["when"],
        $userId
    );
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();

    return ["id" => $id, "stock" => $stock];
}

function updateStockIn(mysqli $conn, int $id, array $d): float
{
    $old = fetchRecord($conn, "stock_in", $id);

    if (!$old) {
        throw new StockException("This record no longer exists.");
    }

    $oldProduct = $old["product_id"] ? (int) $old["product_id"] : 0;
    $oldQty     = (float) $old["quantity"];

    if ($oldProduct === $d["product_id"]) {
        $stock = changeStock($conn, $oldProduct, $d["quantity"] - $oldQty);
    } else {
        if ($oldProduct) {
            changeStock($conn, $oldProduct, -$oldQty);
        }
        $stock = changeStock($conn, $d["product_id"], $d["quantity"]);
    }

    // keep the old time if the date did not change
    $when = (substr($old["received_at"], 0, 10) === substr($d["when"], 0, 10)) ? $old["received_at"] : $d["when"];

    $stmt = $conn->prepare("
        UPDATE stock_in
        SET product_id = ?, supplier_id = ?, quantity = ?, unit_cost = ?, unit = ?, remarks = ?, received_at = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        "iiddsssi",
        $d["product_id"],
        $d["supplier_id"],
        $d["quantity"],
        $d["unit_cost"],
        $d["unit"],
        $d["remarks"],
        $when,
        $id
    );
    $stmt->execute();
    $stmt->close();

    return $stock;
}

function deleteStockIn(mysqli $conn, int $id): void
{
    $old = fetchRecord($conn, "stock_in", $id);

    if (!$old) {
        throw new StockException("This record no longer exists.");
    }

    if ($old["product_id"]) {
        try {
            changeStock($conn, (int) $old["product_id"], -(float) $old["quantity"]);
        } catch (StockException $e) {
            throw new StockException("This record cannot be deleted: some of these units were already sold or used.");
        }
    }

    $stmt = $conn->prepare("DELETE FROM stock_in WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}


/* ===================== STOCK OUT ===================== */

function createStockOut(mysqli $conn, array $d, string $reference, ?int $userId): array
{
    $stock = changeStock($conn, $d["product_id"], -$d["quantity"]);
    $cost  = $d["unit_cost"] ?? productCost($conn, $d["product_id"]);

    $stmt = $conn->prepare("
        INSERT INTO stock_out (product_id, quantity, unit, reason, unit_cost, reference_no, remarks, released_at, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "idssdsssi",
        $d["product_id"],
        $d["quantity"],
        $d["unit"],
        $d["reason"],
        $cost,
        $reference,
        $d["remarks"],
        $d["when"],
        $userId
    );
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();

    return ["id" => $id, "stock" => $stock];
}

function updateStockOut(mysqli $conn, int $id, array $d): float
{
    $old = fetchRecord($conn, "stock_out", $id);

    if (!$old) {
        throw new StockException("This record no longer exists.");
    }

    $oldProduct = $old["product_id"] ? (int) $old["product_id"] : 0;
    $oldQty     = (float) $old["quantity"];

    if ($oldProduct === $d["product_id"]) {
        $stock = changeStock($conn, $oldProduct, $oldQty - $d["quantity"]);
        $cost  = (float) $old["unit_cost"];
    } else {
        if ($oldProduct) {
            changeStock($conn, $oldProduct, $oldQty);
        }
        $stock = changeStock($conn, $d["product_id"], -$d["quantity"]);
        $cost  = productCost($conn, $d["product_id"]);
    }

    $when = (substr($old["released_at"], 0, 10) === substr($d["when"], 0, 10)) ? $old["released_at"] : $d["when"];

    $stmt = $conn->prepare("
        UPDATE stock_out
        SET product_id = ?, quantity = ?, unit = ?, reason = ?, unit_cost = ?, remarks = ?, released_at = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        "idssdssi",
        $d["product_id"],
        $d["quantity"],
        $d["unit"],
        $d["reason"],
        $cost,
        $d["remarks"],
        $when,
        $id
    );
    $stmt->execute();
    $stmt->close();

    return $stock;
}

function deleteStockOut(mysqli $conn, int $id): void
{
    $old = fetchRecord($conn, "stock_out", $id);

    if (!$old) {
        throw new StockException("This record no longer exists.");
    }

    // deleting a stock-out puts the units back
    if ($old["product_id"]) {
        changeStock($conn, (int) $old["product_id"], (float) $old["quantity"]);
    }

    $stmt = $conn->prepare("DELETE FROM stock_out WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}
