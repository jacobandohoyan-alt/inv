<?php
/*
 |  UNITS OF MEASURE
 |  One list for the Products page, Stock In and Stock Out.
 |  The unit belongs to the PRODUCT (set on the Products page).
 |  Stock In / Stock Out always use the unit of the product, they never guess it.
 |
 |  To add a unit later, add one line to productUnits().
 */

function productUnits(): array
{
    // value saved in the database  =>  text shown in the dropdown
    return [
        "pack"   => "pack",
        "kilo"   => "kilo",
        "can"    => "can",
        "galon"  => "galon",
        "carton" => "carton",
        "box"    => "box",
        "case"   => "case",
        "pcs"    => "pieces/pcs",
        "bottle" => "bottle",
        "sachet" => "sachet",
    ];
}

function unitLabel(?string $unit): string
{
    $list = productUnits();

    return $list[(string) $unit] ?? (string) $unit;
}

/*
 |  QUANTITIES
 |  Only products counted in KILO may have a fractional quantity (e.g. 2.5 kilo, 0.250 kilo).
 |  Every other unit (pack, can, galon, carton, box, case, pcs, bottle, sachet) is a whole number.
 |  Quantities are stored with 3 decimals (DECIMAL(12,3)), so 250 grams = 0.25 kilo.
 */

// does this unit allow a fractional quantity?
function unitAllowsDecimal(?string $unit): bool
{
    return strtolower(trim((string) $unit)) === "kilo";
}

// the HTML "step" of a quantity input for this unit
function qtyStep(?string $unit): string
{
    return unitAllowsDecimal($unit) ? "0.001" : "1";
}

// 12.000 -> "12"   2.500 -> "2.5"   0.250 -> "0.25"   (for showing on screen)
function formatQty($value, bool $thousands = true): string
{
    $n = round((float) $value, 3);

    if (abs($n - round($n)) < 0.0005) {
        return number_format($n, 0, ".", $thousands ? "," : "");
    }

    $text = number_format($n, 3, ".", $thousands ? "," : "");

    return rtrim(rtrim($text, "0"), ".");
}

// A quantity typed by the user, checked for the unit of the product.
// Returns the clean number (3 decimals) or throws InvalidArgumentException with a message
// that is safe to show.  $allowZero = true is used for stock levels (0 is fine there).
function cleanQuantity($raw, ?string $unit, bool $allowZero = false): float
{
    $decimal = unitAllowsDecimal($unit);
    $word    = $decimal ? "a number" : "a whole number";

    if (is_string($raw)) {
        $raw = trim($raw);
    }

    if ($raw === "" || $raw === null || !is_numeric($raw)) {
        throw new InvalidArgumentException("Quantity must be " . $word . ($allowZero ? "." : " greater than zero."));
    }

    $n = (float) $raw;

    if (!is_finite($n) || $n < 0 || $n > 1000000 || (!$allowZero && $n <= 0)) {
        throw new InvalidArgumentException("Quantity must be " . $word . ($allowZero ? " from 0 to 1,000,000." : " greater than zero (maximum 1,000,000)."));
    }

    if ($decimal) {
        $rounded = round($n, 3);

        // more than 3 decimals typed (e.g. 1.2345): refuse instead of silently rounding
        if (abs($rounded - $n) > 0.0000001) {
            throw new InvalidArgumentException("Kilo quantities can have at most 3 decimals (for example 2.5 or 0.250).");
        }

        if (!$allowZero && $rounded <= 0) {
            throw new InvalidArgumentException("Quantity must be greater than zero.");
        }

        return $rounded;
    }

    if (abs($n - round($n)) > 0.0000001) {
        throw new InvalidArgumentException("This product is counted in " . unitLabel($unit) . ", so the quantity must be a whole number. Only products in kilo can have decimals.");
    }

    return (float) round($n);
}

// Quantity columns must be able to hold decimals (kilo). Changes INT -> DECIMAL(12,3) once.
// Same as running database/make_quantities_decimal.sql. Safe to run many times.
function ensureDecimalQuantityColumns(mysqli $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $columns = [
        ["products",  "stock_qty",     "DECIMAL(12,3) NOT NULL DEFAULT 0.000"],
        ["products",  "reorder_level", "DECIMAL(12,3) NOT NULL DEFAULT 10.000"],
        ["stock_in",  "quantity",      "DECIMAL(12,3) NOT NULL"],
        ["stock_out", "quantity",      "DECIMAL(12,3) NOT NULL"],
    ];

    try {
        foreach ($columns as [$table, $column, $definition]) {

            $stmt = $conn->prepare("
                SELECT DATA_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->bind_param("ss", $table, $column);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();

            if ($row && strtolower((string) $row[0]) !== "decimal") {
                $conn->query("ALTER TABLE `$table` MODIFY `$column` $definition");
            }
        }
    } catch (Throwable $e) {
        // no permission to change the table: run database/make_quantities_decimal.sql in phpMyAdmin
        error_log("ensureDecimalQuantityColumns: " . $e->getMessage());
    }
}

// Makes sure the `products` table has the `unit` column. If it is missing it is added once
// (same as running database/add_product_unit.sql). Old products get the unit of their last
// stock record when it matches the new list, otherwise "pcs" (edit the product to change it).
function ensureProductUnitColumn(mysqli $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    ensureDecimalQuantityColumns($conn);   // kilo quantities need decimals

    try {
        $res = $conn->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'unit'
        ");

        if ($res && (int) $res->fetch_row()[0] > 0) {
            return;
        }

        $conn->query("ALTER TABLE products ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER category");

        // old spellings -> the new list
        $alias = ["kg" => "kilo", "piece" => "pcs", "pieces" => "pcs", "gallon" => "galon"];
        $last  = [];

        $rows = $conn->query("
            SELECT product_id, unit FROM (
                SELECT product_id, unit, received_at AS happened, id FROM stock_in
                UNION ALL
                SELECT product_id, unit, released_at AS happened, id FROM stock_out
            ) t
            WHERE product_id IS NOT NULL AND unit IS NOT NULL AND unit <> ''
            ORDER BY happened ASC, id ASC
        ");

        while ($rows && $r = $rows->fetch_assoc()) {
            $last[(int) $r["product_id"]] = strtolower(trim($r["unit"]));   // the newest record wins
        }

        $stmt = $conn->prepare("UPDATE products SET unit = ? WHERE id = ?");

        foreach ($last as $productId => $unit) {
            $unit = $alias[$unit] ?? $unit;

            if (isset(productUnits()[$unit])) {
                $stmt->bind_param("si", $unit, $productId);
                $stmt->execute();
            }
        }

        $stmt->close();

    } catch (Throwable $e) {
        // no permission to change the table: run database/add_product_unit.sql in phpMyAdmin
        error_log("ensureProductUnitColumn: " . $e->getMessage());
    }
}
