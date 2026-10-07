<?php
/*
 |  Logs the user out: clears the session data, the session cookie, then goes to the login page.
 */

session_start();

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
}

session_destroy();

// a fresh session just to carry the "logged out" message
session_start();
$_SESSION["success"] = "You have been logged out.";

header("Location: ../login.php");
exit();
