<?php
/*
 |  Record / edit / delete business expenses (Suppliers page).
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
requirePermissionForm("expenses.manage");

require_once __DIR__ . "/../database/connect.php";
require_once __DIR__ . "/expense_helpers.php";

date_default_timezone_set("Asia/Manila");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../suppliers.php#expenses");
    exit;
}

// CSRF: the forms on suppliers.php send a hidden token
if (!csrfValid()) {
    header("Location: ../suppliers.php?error=" . urlencode("Your session expired. Please refresh the page and try again.") . "#expenses");
    exit;
}

function back(string $type, string $message): void
{
    header("Location: ../suppliers.php?" . $type . "=" . urlencode($message) . "#expenses");
    exit;
}

$action = $_POST["action"] ?? "";


/* ---------- create / update ---------- */
if ($action === "create" || $action === "update") {

    $id          = (int) ($_POST["id"] ?? 0);
    $date        = (string) ($_POST["expense_date"] ?? "");
    $category    = trim((string) ($_POST["category"] ?? ""));
    $description = trim(preg_replace("/\s+/", " ", (string) ($_POST["description"] ?? "")));
    $amount      = round((float) ($_POST["amount"] ?? 0), 2);
    $method      = trim((string) ($_POST["payment_method"] ?? ""));
    $supplier_id = ((int) ($_POST["supplier_id"] ?? 0)) > 0 ? (int) $_POST["supplier_id"] : null;

    $parsed = DateTime::createFromFormat("Y-m-d", $date);

    if (!$parsed || $parsed->format("Y-m-d") !== $date) {
        back("error", "Please enter a valid date");
    }

    if ($date > date("Y-m-d")) {
        back("error", "The date cannot be in the future");
    }

    if (!in_array($category, expenseCategories(), true)) {
        back("error", "Please select a category");
    }

    if ($description === "" || mb_strlen($description) > 255) {
        back("error", "Please enter a description (up to 255 characters)");
    }

    if ($amount <= 0 || $amount > 9999999999) {
        back("error", "The amount must be greater than zero");
    }

    if (!in_array($method, paymentMethods(), true)) {
        back("error", "Please select a payment method");
    }

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

    try {

        if ($action === "create") {

            $userId = isset($_SESSION["user"]["id"]) ? (int) $_SESSION["user"]["id"] : null;

            $stmt = $conn->prepare("
                INSERT INTO expenses (expense_date, category, description, amount, payment_method, supplier_id, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssdsii", $date, $category, $description, $amount, $method, $supplier_id, $userId);
            $stmt->execute();
            $stmt->close();

            back("success", "Expense recorded successfully");
        }

        // update
        $check = $conn->prepare("SELECT id FROM expenses WHERE id = ? LIMIT 1");
        $check->bind_param("i", $id);
        $check->execute();
        $found = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$found) {
            back("error", "Expense not found");
        }

        $stmt = $conn->prepare("
            UPDATE expenses
            SET expense_date = ?, category = ?, description = ?, amount = ?, payment_method = ?, supplier_id = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sssdsii", $date, $category, $description, $amount, $method, $supplier_id, $id);
        $stmt->execute();
        $stmt->close();

    } catch (mysqli_sql_exception $e) {

        back("error", "Unable to save the expense");
    }

    back("success", "Expense updated successfully");
}


/* ---------- delete ---------- */
if ($action === "delete") {

    $id = (int) ($_POST["id"] ?? 0);

    if ($id <= 0) {
        back("error", "Invalid expense");
    }

    try {

        $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

    } catch (mysqli_sql_exception $e) {

        back("error", "Unable to delete the expense");
    }

    back($deleted > 0 ? "success" : "error", $deleted > 0 ? "Expense deleted successfully" : "Expense not found");
}

header("Location: ../suppliers.php#expenses");
exit;