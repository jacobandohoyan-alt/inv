<?php

include('controller/authenticator.php');
requirePermission('users.manage');   // role check: staff typing this URL are sent to access_denied.php
include('database/connect.php');

// the password column is not needed here, so it is not loaded
$sql = "SELECT id, username, position FROM users";
$result = $conn->query($sql);

$userCount = $result->num_rows;

$flashSuccess = $_SESSION['success'] ?? '';
$flashError   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Users</title>

    <link
        rel="stylesheet"
        type="text/css"
        href="tailwind.min.css"
    >

    <script
        src="https://kit.fontawesome.com/7fa31b9a7a.js"
        crossorigin="anonymous">
    </script>

</head>

<body class="bg-purple-200">

    <div class="grid grid-cols-1 lg:grid-cols-6 min-h-screen">

        <?php
            $activePage = "users";
            include "partials/sidebar.php";
        ?>

        <main class="lg:col-span-5 bg-purple-200 min-h-screen">

            <div class="bg-purple-700 p-5 shadow-lg">

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

                    <div>

                        <p class="text-purple-200 text-sm">
                            User Management
                        </p>

                        <h1 class="text-2xl font-bold text-white">
                            Users
                        </h1>

                    </div>

                    <div class="flex items-center gap-3 bg-purple-600 rounded-xl px-4 py-3">

                        <div class="w-10 h-10 rounded-full bg-purple-500 flex items-center justify-center">

                            <i class="fa-solid fa-user text-white"></i>

                        </div>

                        <div class="text-left">

                            <p class="text-xs text-purple-200">
                                Logged in as
                            </p>

                            <p class="text-sm font-bold text-white">
                                <?= htmlspecialchars(strtoupper($_SESSION["user"]["username"]), ENT_QUOTES, "UTF-8") ?>
                            </p>

                        </div>

                    </div>

                </div>

            </div>

            <div class="p-6">

                <?php if ($flashSuccess): ?>
                    <div class="mb-5 bg-green-100 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, "UTF-8") ?></div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="mb-5 bg-red-100 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl"><?= htmlspecialchars($flashError, ENT_QUOTES, "UTF-8") ?></div>
                <?php endif; ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5 mb-7">

                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">

                        <div class="flex items-center justify-between">

                            <div>

                                <p class="text-sm text-slate-500">
                                    Total Users
                                </p>

                                <h2 class="text-3xl font-bold text-slate-900 mt-1">
                                    <?= $userCount ?>
                                </h2>

                                <p class="text-xs text-green-600 mt-2">
                                    <i class="fa-solid fa-circle-check mr-1"></i>
                                    Registered accounts
                                </p>

                            </div>

                            <div class="w-14 h-14 rounded-2xl bg-violet-100 flex items-center justify-center">

                                <i class="fa-solid fa-users text-violet-600 text-2xl"></i>

                            </div>

                        </div>

                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">

                        <div class="flex items-center justify-between">

                            <div>

                                <p class="text-sm text-slate-500">
                                    User Accounts
                                </p>

                                <h2 class="text-3xl font-bold text-slate-900 mt-1">
                                    <?= $userCount ?>
                                </h2>

                                <p class="text-xs text-purple-600 mt-2">
                                    <i class="fa-solid fa-user-check mr-1"></i>
                                    System users
                                </p>

                            </div>

                            <div class="w-14 h-14 rounded-2xl bg-purple-100 flex items-center justify-center">

                                <i class="fa-solid fa-user-check text-purple-600 text-2xl"></i>

                            </div>

                        </div>

                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">

                        <div class="flex items-center justify-between">

                            <div>

                                <p class="text-sm text-slate-500">
                                    System Access
                                </p>

                                <h2 class="text-3xl font-bold text-slate-900 mt-1">
                                    Active
                                </h2>

                                <p class="text-xs text-green-600 mt-2">
                                    <i class="fa-solid fa-shield-halved mr-1"></i>
                                    Protected
                                </p>

                            </div>

                            <div class="w-14 h-14 rounded-2xl bg-green-100 flex items-center justify-center">

                                <i class="fa-solid fa-shield-halved text-green-600 text-2xl"></i>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">

                    <div class="p-6 border-b border-slate-200">

                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">

                            <div>

                                <div class="flex items-center gap-3">

                                    <div class="w-11 h-11 rounded-xl bg-purple-100 flex items-center justify-center">

                                        <i class="fa-solid fa-people-group text-purple-700 text-lg"></i>

                                    </div>

                                    <div>

                                        <h2 class="text-xl font-bold text-slate-900">
                                            User Management
                                        </h2>

                                        <p class="text-sm text-slate-500">
                                            Manage your registered users
                                        </p>

                                        <a href="registration.php" class="inline-block mt-2 text-sm font-semibold text-purple-700 hover:underline">
                                            <i class="fa-solid fa-user-plus mr-1"></i>Add a new user
                                        </a>

                                    </div>

                                </div>

                            </div>

                            <div class="relative w-full lg:w-80">

                                <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>

                                <input
                                    type="text"
                                    id="userSearch"
                                    placeholder="Search users..."
                                    class="w-full border border-slate-200 rounded-xl py-3 pl-11 pr-4 outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-100"
                                >

                            </div>

                        </div>

                    </div>

                    <div class="p-6">

                        <div class="overflow-x-auto">

                            <table class="w-full min-w-[700px]">

                                <thead>

                                    <tr class="bg-purple-100">

                                        <th class="px-6 py-4 text-left text-sm font-bold text-purple-900 rounded-l-xl">
                                            User
                                        </th>

                                        <th class="px-6 py-4 text-left text-sm font-bold text-purple-900">
                                            Position
                                        </th>

                                        <th class="px-6 py-4 text-center text-sm font-bold text-purple-900">
                                            Status
                                        </th>

                                        <th class="px-6 py-4 text-center text-sm font-bold text-purple-900 rounded-r-xl">
                                            Action
                                        </th>

                                    </tr>

                                </thead>

                                <tbody id="userTable">

                                    <?php while ($row = $result->fetch_assoc()) { ?>

                                        <tr
                                            class="user-row border-b border-slate-100 hover:bg-purple-50 transition"
                                            data-search="<?= strtolower(htmlspecialchars($row['username'] . ' ' . $row['position'])) ?>"
                                        >

                                            <td class="px-6 py-5">

                                                <div class="flex items-center gap-4">

                                                    <div class="w-11 h-11 rounded-full bg-purple-100 flex items-center justify-center">

                                                        <i class="fa-solid fa-user text-purple-700"></i>

                                                    </div>

                                                    <div>

                                                        <p class="font-bold text-slate-900">

                                                            <?= htmlspecialchars($row['username']) ?>

                                                        </p>

                                                        <p class="text-xs text-slate-400 mt-1">

                                                            User ID:
                                                            <?= htmlspecialchars($row['id']) ?>

                                                        </p>

                                                    </div>

                                                </div>

                                            </td>

                                            <td class="px-6 py-5">

                                                <span class="inline-flex items-center gap-2 bg-purple-100 text-purple-800 px-4 py-2 rounded-full text-sm font-semibold">

                                                    <i class="fa-solid fa-user-tie text-xs"></i>

                                                    <?= htmlspecialchars($row['position']) ?>

                                                </span>

                                            </td>

                                            <td class="px-6 py-5 text-center">

                                                <span class="inline-flex items-center gap-2 bg-green-100 text-green-700 px-4 py-2 rounded-full text-sm font-semibold">

                                                    <span class="w-2 h-2 rounded-full bg-green-500"></span>

                                                    Active

                                                </span>

                                            </td>

                                            <td class="px-6 py-5 text-center">

                                                <form
                                                    action="controller/delete.php"
                                                    method="POST"
                                                    onsubmit="return confirm('Delete this user?');"
                                                >

                                                    <?= csrfField() ?>

                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?= (int) $row['id'] ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="inline-flex items-center justify-center gap-2 bg-red-100 text-red-600 hover:bg-red-600 hover:text-white px-4 py-2 rounded-xl font-semibold transition"
                                                    >

                                                        <i class="fa-solid fa-trash"></i>

                                                        Delete

                                                    </button>

                                                </form>

                                            </td>

                                        </tr>

                                    <?php } ?>

                                </tbody>

                            </table>

                        </div>

                        <div
                            id="noResults"
                            class="hidden text-center py-12"
                        >

                            <div class="w-16 h-16 mx-auto rounded-full bg-purple-100 flex items-center justify-center">

                                <i class="fa-solid fa-magnifying-glass text-purple-600 text-2xl"></i>

                            </div>

                            <h3 class="text-lg font-bold text-slate-900 mt-4">
                                No Users Found
                            </h3>

                            <p class="text-sm text-slate-500 mt-1">
                                Try searching for another username or position.
                            </p>

                        </div>

                        <?php if ($userCount == 0) { ?>

                            <div class="text-center py-12">

                                <div class="w-16 h-16 mx-auto rounded-full bg-purple-100 flex items-center justify-center">

                                    <i class="fa-solid fa-users text-purple-600 text-2xl"></i>

                                </div>

                                <h3 class="text-lg font-bold text-slate-900 mt-4">
                                    No Users Yet
                                </h3>

                                <p class="text-sm text-slate-500 mt-1">
                                    There are currently no registered users.
                                </p>

                            </div>

                        <?php } ?>

                    </div>

                </div>

                <div class="mt-6 bg-purple-900 rounded-2xl p-6 shadow-lg">

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">

                        <div class="flex items-center gap-4">

                            <div class="w-12 h-12 rounded-xl bg-purple-700 flex items-center justify-center">

                                <i class="fa-solid fa-circle-info text-purple-200 text-xl"></i>

                            </div>

                            <div>

                                <h3 class="font-bold text-white">
                                    User Management
                                </h3>

                                <p class="text-sm text-purple-300 mt-1">
                                    Manage system accounts carefully.
                                </p>

                            </div>

                        </div>

                        <div class="text-sm text-purple-300">

                            <i class="fa-solid fa-users mr-1"></i>

                            <?= $userCount ?> registered user(s)

                        </div>

                    </div>

                </div>

            </div>

        </main>

    </div>

    <script>

        const searchInput = document.getElementById('userSearch');
        const userRows = document.querySelectorAll('.user-row');
        const noResults = document.getElementById('noResults');

        searchInput.addEventListener('input', function () {

            const searchValue = this.value.toLowerCase().trim();

            let visibleRows = 0;

            userRows.forEach(function (row) {

                const searchData = row.getAttribute('data-search');

                if (searchData.includes(searchValue)) {

                    row.classList.remove('hidden');

                    visibleRows++;

                } else {

                    row.classList.add('hidden');

                }

            });

            if (visibleRows === 0 && searchValue !== '') {

                noResults.classList.remove('hidden');

            } else {

                noResults.classList.add('hidden');

            }

        });

    </script>

</body>

</html>