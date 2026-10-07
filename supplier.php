<?php
/*
 |  Add / edit / delete suppliers (Suppliers page).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION["user"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/permissions.php";
requirePermissionForm("suppliers.manage");

require_once __DIR__ . "/../database/connect.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../suppliers.php");
    exit;
}

// CSRF: the forms on suppliers.php send a hidden token
if (!csrfValid()) {
    header("Location: ../suppliers.php?error=" . urlencode("Your session expired. Please refresh the page and try again."));
    exit;
}

function back(string $type, string $message): void
{
    header("Location: ../suppliers.php?" . $type . "=" . urlencode($message));
    exit;
}

$action = $_POST["action"] ?? "";


/* ---------- create / update ---------- */
if ($action === "create" || $action === "update") {

    $id      = (int) ($_POST["id"] ?? 0);
    $name    = trim(preg_replace("/\s+/", " ", (string) ($_POST["name"] ?? "")));
    $contact = trim((string) ($_POST["contact_person"] ?? ""));
    $phone   = trim((string) ($_POST["phone"] ?? ""));
    $email   = trim((string) ($_POST["email"] ?? ""));
    $address = trim((string) ($_POST["address"] ?? ""));
    $status  = (($_POST["status"] ?? "") === "pending") ? "pending" : "active";

    if ($name === "") {
        back("error", "Supplier name is required");
    }

    if (mb_strlen($name) > 100 || mb_strlen($contact) > 100 || mb_strlen($email) > 100 || mb_strlen($address) > 255) {
        back("error", "One of the fields is too long");
    }

    if ($phone !== "" && !preg_match("/^[0-9+\-\s()]{7,30}$/", $phone)) {
        back("error", "Please enter a valid phone number");
    }

    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        back("error", "Please enter a valid email address");
    }

    // empty fields are saved as NULL
    $contact = $contact === "" ? null : $contact;
    $phone   = $phone === "" ? null : $phone;
    $email   = $email === "" ? null : $email;
    $address = $address === "" ? null : $address;

    $dup = $conn->prepare("SELECT id FROM suppliers WHERE name = ? AND id != ? LIMIT 1");
    $dup->bind_param("si", $name, $id);
    $dup->execute();
    $nameTaken = $dup->get_result()->num_rows > 0;
    $dup->close();

    if ($nameTaken) {
        back("error", "A supplier with this name already exists");
    }

    try {

        if ($action === "create") {

            $stmt = $conn->prepare("
                INSERT INTO suppliers (name, contact_person, phone, email, address, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("ssssss", $name, $contact, $phone, $email, $address, $status);
            $stmt->execute();
            $stmt->close();

            back("success", "Supplier added successfully");
        }

        // update
        $check = $conn->prepare("SELECT id FROM suppliers WHERE id = ? LIMIT 1");
        $check->bind_param("i", $id);
        $check->execute();
        $found = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$found) {
            back("error", "Supplier not found");
        }

        $stmt = $conn->prepare("
            UPDATE suppliers
            SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?, status = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssssi", $name, $contact, $phone, $email, $address, $status, $id);
        $stmt->execute();
        $stmt->close();

    } catch (mysqli_sql_exception $e) {

        back("error", "Unable to save the supplier");
    }

    back("success", "Supplier updated successfully");
}


/* ---------- delete ---------- */
if ($action === "delete") {

    $id = (int) ($_POST["id"] ?? 0);

    if ($id <= 0) {
        back("error", "Invalid supplier");
    }

    try {

        $conn->begin_transaction();

        $find = $conn->prepare("SELECT name FROM suppliers WHERE id = ? FOR UPDATE");
        $find->bind_param("i", $id);
        $find->execute();
        $supplier = $find->get_result()->fetch_assoc();
        $find->close();

        if (!$supplier) {
            $conn->rollback();
            back("error", "Supplier not found");
        }

        // products, stock records and expenses stay: they just lose the supplier
        $products = $conn->prepare("UPDATE products SET supplier_id = NULL WHERE supplier_id = ?");
        $products->bind_param("i", $id);
        $products->execute();
        $changed = $products->affected_rows;
        $products->close();

        foreach (["stock_in", "expenses"] as $table) {
            $other = $conn->prepare("UPDATE $table SET supplier_id = NULL WHERE supplier_id = ?");
            $other->bind_param("i", $id);
            $other->execute();
            $other->close();
        }

        $delete = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
        $delete->bind_param("i", $id);
        $delete->execute();
        $delete->close();

        $conn->commit();

    } catch (Throwable $e) {

        $conn->rollback();
        back("error", "Unable to delete the supplier");
    }

    $message = 'Supplier "' . $supplier["name"] . '" deleted';

    if ($changed > 0) {
        $message .= " (" . $changed . " product(s) now have no supplier)";
    }

    back("success", $message);
}

header("Location: ../suppliers.php");
exit;