<?php
/*
 |  Checks the username + password and starts the session.
 */

require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/../database/connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../login.php");
    exit();
}

function fail(string $message): void
{
    $_SESSION["error"] = $message;
    header("Location: ../login.php");
    exit();
}

$username = trim((string) ($_POST["username"] ?? ""));
$password = (string) ($_POST["password"] ?? "");

if ($username === "" || $password === "") {
    fail("Please enter your username and password.");
}

// prepared statement: the input can never change the SQL
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// same message for "no such user" and "wrong password"
if (!$user || !checkPassword($password, (string) $user["password"])) {
    fail("Incorrect username or password.");
}

// old md5 accounts are upgraded to bcrypt the first time they log in
if (needsRehash($conn, (string) $user["password"])) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $upd->bind_param("si", $newHash, $user["id"]);
    $upd->execute();
    $upd->close();
}

// the password hash is never kept in the session
unset($user["password"]);

// new session id after login (prevents session fixation)
session_regenerate_id(true);
$_SESSION["user"] = $user;

header("Location: ../dashboard.php");
exit();
