<?php
/*
 |  Include at the top of every private page.
 |  1. Sends visitors who are not logged in to the login page.
 |  2. Loads the role / permission helpers (can(), requirePermission() ...).
 |  3. Re-reads the user's role from the database, so a changed role or a
 |     deleted account takes effect on the very next click.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// stops the browser from showing private pages from its cache after logout (Back button)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (empty($_SESSION["user"])) {
    $_SESSION["error"] = "Please Login First.";
    header("Location: login.php");
    exit();
}

require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/permissions.php";

if (!refreshSessionUser()) {
    $_SESSION["error"] = "Your account no longer exists. Please contact the Admin.";
    header("Location: login.php");
    exit();
}
