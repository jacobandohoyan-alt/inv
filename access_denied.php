<?php
/*
 |  Shown when a logged-in user opens a page that his or her role is not allowed to use.
 |  It only needs a login (never a permission), so it can never redirect in a loop.
 */

include('controller/authenticator.php');

http_response_code(403);

$username = $_SESSION['user']['username'] ?? 'User';
$role     = ucfirst(currentRole() ?: 'unknown');
$home     = firstAllowedPage();
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Access Denied</title>

    <link rel="stylesheet" href="tailwind.min.css">

    <script src="https://kit.fontawesome.com/7fa31b9a7a.js" crossorigin="anonymous"></script>

    <style>
        body { overflow-x: hidden; }
    </style>

</head>

<body class="bg-purple-200">

    <div class="grid grid-cols-1 lg:grid-cols-6 min-h-screen">

        <?php
            $activePage = "";
            include "partials/sidebar.php";
        ?>

        <main class="lg:col-span-5 bg-purple-200 min-h-screen">

            <div class="bg-purple-700 p-6 shadow-lg">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">

                    <div>
                        <p class="text-purple-200 text-sm mb-1">Security</p>
                        <h1 class="text-3xl font-bold text-white">Access Denied</h1>
                    </div>

                    <div class="flex items-center gap-3 bg-purple-600 rounded-xl px-5 py-3">
                        <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-purple-500">
                            <i class="fa-solid fa-user text-white text-lg"></i>
                        </div>
                        <div>
                            <p class="text-xs text-purple-200">Logged in as <?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="text-sm font-bold text-white"><?= htmlspecialchars(strtoupper($username), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>

                </div>
            </div>

            <div class="p-6 sm:p-7 lg:p-8">
                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm max-w-xl mx-auto p-10 text-center mt-10">

                    <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-red-100 mb-6">
                        <i class="fa-solid fa-lock text-red-600 text-3xl"></i>
                    </div>

                    <h2 class="text-2xl font-bold text-slate-900 mb-3">You cannot open this page</h2>

                    <p class="text-slate-500 mb-8">
                        <?php if ($home): ?>
                            Your account role (<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>) does not have permission to use this page or action.
                            If you need access, please ask an Admin.
                        <?php else: ?>
                            Your account role is not recognised, so it has no access. Please ask an Admin to fix it.
                        <?php endif; ?>
                    </p>

                    <?php if ($home): ?>
                        <a href="<?= htmlspecialchars($home, ENT_QUOTES, 'UTF-8') ?>"
                           class="inline-block bg-purple-700 hover:bg-purple-800 text-white font-semibold rounded-xl px-6 py-3 transition">
                            <i class="fa-solid fa-arrow-left mr-2"></i>Go back to my page
                        </a>
                    <?php else: ?>
                        <a href="controller/logout.php"
                           class="inline-block bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl px-6 py-3 transition">
                            <i class="fa-solid fa-right-from-bracket mr-2"></i>Logout
                        </a>
                    <?php endif; ?>

                </div>
            </div>

        </main>

    </div>

</body>

</html>
