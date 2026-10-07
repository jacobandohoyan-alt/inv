<?php
/*
 |  Deletes a user account (Users page).
 |  Only a logged-in Admin may do it, only by POST with a valid CSRF token.
 */

require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/../database/connect.php";

if (empty($_SESSION["user"])) {
    header("Location: ../login.php");
    exit();
}

function back(string $type, string $message): void
{
    $_SESSION[$type] = $message;
    header("Location: ../users.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !csrfValid()) {
    back("error", "Invalid request. Please try again.");
}

refreshSessionUser();

if (!can("users.manage")) {
    back("error", "Only an Admin can delete users.");
}

$id = (int) ($_POST["id"] ?? 0);

if ($id <= 0) {
    back("error", "Invalid user.");
}

if ($id === (int) $_SESSION["user"]["id"]) {
    back("error", "You cannot delete your own account while logged in.");
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    // e.g. the user is already linked to stock records
    back("error", "This user cannot be deleted because they have recorded stock activity.");
}

back($deleted > 0 ? "success" : "error", $deleted > 0 ? "User deleted." : "User not found.");
