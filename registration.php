<?php
    require_once __DIR__ . '/controller/auth_helpers.php';   // starts the session, CSRF helpers
    require_once __DIR__ . '/controller/permissions.php';
    require_once __DIR__ . '/database/connect.php';

    // Only an Admin may create accounts. The only exception is the very first account of an empty system.
    $noUsers = ((int) $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0]) === 0;
    $isAdmin = !empty($_SESSION['user']) && refreshSessionUser() && can('users.manage');

    if (!$noUsers && !$isAdmin) {
        $_SESSION['error'] = 'Only an Admin can create accounts. Please ask your Admin.';
        header('Location: login.php');
        exit();
    }

    $error = $_SESSION['error'] ?? '';
    unset($_SESSION['error']);

    $success = $_SESSION['success'] ?? '';
    unset($_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Account</title>

    <link rel="stylesheet" href="tailwind.min.css">

    <!-- Font Awesome -->
    <script
        src="https://kit.fontawesome.com/7fa31b9a7a.js"
        crossorigin="anonymous">
    </script>
</head>

<body class="min-h-screen bg-purple-700 flex items-center justify-center px-4">

    <div class="bg-purple-600 shadow-lg rounded-xl px-10 py-10 w-full max-w-md">

        <div class="text-center mb-8">

            <h1 class="text-5xl font-bold text-white">
                Create Account
            </h1>

            <p class="text-white mt-3">
                <?= $noUsers ? 'First setup: this account will be the Admin' : 'Create an account for a new user' ?>
            </p>

        </div>

        <form method="POST" action="controller/register.php">

            <?= csrfField() ?>

            <!-- Username -->
            <div class="mb-1">

                <label class="block text-white font-semibold mb-2">
                    Username
                </label>

                <div class="relative">

                    <i
                        class="fa-solid fa-user absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-500 pointer-events-none">
                    </i>

                    <input
                        type="text"
                        name="username"
                        placeholder="Enter Username"
                        required
                        minlength="2"
                        class="w-full rounded-xl bg-white py-3 pl-11  pr-4 outline-none focus:ring-2 focus:ring-blue-500"
                    >

                </div>

            </div>


            <!-- Position -->
            <div class="mb-1">

                <label class="block text-white font-semibold mb-2">
                    Position
                </label>

                <div class="relative">

                    <i
                        class="fa-solid fa-briefcase absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-500 pointer-events-none">
                    </i>

                    <select
                        name="position"
                        <?= $noUsers ? '' : 'required' ?>
                        class="w-full rounded-xl bg-white py-3 pl-11 pr-4 outline-none focus:ring-2 focus:ring-blue-500"
                    >

                        <option value="" disabled selected>
                            Position
                        </option>

                        <option value="Admin">
                            Admin
                        </option>

                        <option value="Staff">
                            Staff
                        </option>

                    </select>

                </div>

            </div>


            <!-- Create Password -->
            <div class="mb-1">

                <label class="block text-white font-semibold mb-2">
                    Create Password
                </label>

                <div class="relative">

                    <i
                        class="fa-solid fa-lock absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-500 pointer-events-none">
                    </i>

                    <input
                        type="password"
                        name="password"
                        placeholder="PxH1#n!8"
                        required
                        minlength="8"
                        class="w-full rounded-xl bg-white py-3 pl-11 pr-4 outline-none focus:ring-2 focus:ring-blue-500"
                    >

                </div>

            </div>


            <!-- Confirm Password -->
            <div class="mb-1">

                <label class="block text-white font-semibold mb-2">
                    Confirm Password
                </label>

                <div class="relative">

                    <i
                        class="fa-solid fa-lock absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-500 pointer-events-none">
                    </i>

                    <input
                        type="password"
                        name="confirm_password"
                        placeholder="Confirm password"
                        required
                        minlength="8"
                        class="w-full rounded-xl bg-white py-3 pl-11 pr-4 outline-none focus:ring-2 focus:ring-blue-500"
                    >

                </div>

            </div>


            <!-- Create Account Button -->
            <button
                type="submit"
                class="w-full mt-6 bg-indigo-600 hover:bg-indigo-700 transition duration-300 text-white text-xl font-semibold py-3 rounded-xl shadow-lg"
            >
                Create Account
            </button>

        </form>


        <!-- Error Message -->
        <?php if ($error): ?>

            <div class="w-full mt-4 bg-red-300 text-white text-md text-center py-2 rounded-xl">
                <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?>
            </div>

        <?php endif; ?>


        <!-- Back -->
        <p class="text-center text-white mt-8">

            <?php if ($isAdmin): ?>
                <a href="users.php" class="font-bold hover:underline">Back to Users</a>
            <?php else: ?>
                Already have an account?
                <a href="login.php" class="font-bold hover:underline">Login</a>
            <?php endif; ?>

        </p>

    </div>

</body>
</html>