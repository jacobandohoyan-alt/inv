<?php
/*
 |  Shared helpers for products (used by products.php and controller/product.php)
 */

require_once __DIR__ . "/unit_helpers.php";

// Category name => SKU prefix.
// To add a category later, just add one line here.
function productCategories(): array
{
    return [
        "Kitchen"     => "KIT",
        "Bar Counter" => "BAR",
        "Necessities" => "NEC",
        "Packaging"   => "PKG",
    ];
}

// Next free SKU for a prefix, e.g. MLT-0001, MLT-0002, ...
function nextSku(mysqli $conn, string $prefix): string
{
    $like = $prefix . "-%";

    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING(sku, 5) AS UNSIGNED)), 0)
        FROM products
        WHERE sku LIKE ?
    ");

    $stmt->bind_param("s", $like);
    $stmt->execute();
    $max = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    return $prefix . "-" . str_pad((string) ($max + 1), 4, "0", STR_PAD_LEFT);
}