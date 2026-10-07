<?php
/*
 |  Creates an account with the position chosen in the form (Admin or Staff).
 |  The position decides the access: Admin = all features, Staff = limited
 |  (see controller/permissions.php).
 */

require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/../database/connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../registration.php");
    exit();
}

function back(string $message): void
{
    $_SESSION["error"] = $message;
    header("Location: ../registration.php");
    exit();
}

// WHO MAY CREATE AN ACCOUNT
//  - a logged-in Admin (Users page -> "Add a new user")
//  - anybody, but ONLY while there are no users at all (the very first account, which becomes Admin)
// Everybody else is refused: otherwise a stranger could register himself as Admin.
$noUsers = ((int) $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0]) === 0;
$byAdmin = !empty($_SESSION["user"]) && refreshSessionUser() && can("users.manage");

if (!$noUsers && !$byAdmin) {
    $_SESSION["error"] = "Only an Admin can create accounts. Please ask your Admin.";
    header("Location: ../login.php");
    exit();
}

if (!csrfValid()) {
    back("Your session expired. Please refresh the page and try again.");
}

$username = trim((string) ($_POST["username"] ?? ""));
$password = (string) ($_POST["password"] ?? "");
$confirm  = (string) ($_POST["confirm_password"] ?? "");

if (mb_strlen($username) < 2 || mb_strlen($username) > 50) {
    back("Username must be 2 to 50 characters.");
}

if (strlen($password) < 8) {
    back("Password must be at least 8 characters.");
}

if ($password !== $confirm) {
    back("Passwords do not match.");
}

// the position chosen in the form (only the values in userPositions() are accepted)
$position = (string) ($_POST["position"] ?? "");

if ($noUsers) {
    $position = "Admin";   // the first account of a new system must be an Admin
}

if (!in_array($position, userPositions(), true)) {
    back("Please select a valid position.");
}

// username must be free
$check = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$check->bind_param("s", $username);
$check->execute();
$taken = $check->get_result()->num_rows > 0;
$check->close();

if ($taken) {
    back("That username is already taken.");
}

$hash = hashPassword($conn, $password);

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $stmt = $conn->prepare("INSERT INTO users (username, position, password) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $position, $hash);
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    // the real database error is not shown to the visitor
    back("Could not create the account. Please try again.");
}

if ($byAdmin) {
    $_SESSION["success"] = "User \"" . $username . "\" was created as " . $position . ".";
    header("Location: ../users.php");
    exit();
}

$_SESSION["success"] = "Registered successfully. You can now log in.";
header("Location: ../login.php");
exit();
