<?php
/*
 |  Shared sidebar.
 |  Use it on any page:
 |      $activePage = "stock";            // dashboard | products | stock | suppliers | reports | users
 |      include "partials/sidebar.php";
 */

$activePage = $activePage ?? "";

// only the links this user is allowed to use (the list lives in controller/permissions.php)
require_once __DIR__ . "/../controller/permissions.php";

$menu = visibleMenu();
?>
<aside class="bg-purple-900 lg:col-span-1 min-h-screen">
    <div class="p-5">
        <div class="bg-purple-800 rounded-2xl p-4 flex items-center gap-3 shadow-lg">
            <img class="h-16 w-16 rounded-full object-cover border-2 border-purple-300 flex-shrink-0" src="images/omg.jpg" alt="OMG Butuan Logo">
            <div class="text-white text-xs font-bold leading-5">
                <div>OMG BUTUAN</div>
                <div>MILK SHAKE</div>
                <div>MILKTEA & COFFE</div>
            </div>
        </div>
    </div>
    <div class="px-3 pb-6">
        <p class="text-purple-300 text-xs font-semibold uppercase px-4 mb-3">Main Menu</p>
        <ul>
            <?php foreach ($menu as $key => [$href, $icon, $label, $needs]): ?>
                <li>
                    <a
                        href="<?= $href ?>"
                        class="flex items-center gap-3 w-full p-4 mb-2 rounded-xl <?= $key === $activePage
                            ? "bg-purple-700 text-white font-semibold shadow-lg"
                            : "text-white font-semibold transition hover:bg-purple-700" ?>"
                    >
                        <i class="fa-solid <?= $icon ?> w-6 text-center"></i>
                        <span><?= $label ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="border-t border-purple-700 my-5"></div>
        <p class="text-purple-300 text-xs font-semibold uppercase px-4 mb-3">Account</p>
        <a href="controller/logout.php" class="flex items-center gap-3 w-full p-4 rounded-xl text-white font-semibold transition hover:bg-red-600">
            <i class="fa-solid fa-right-from-bracket w-6 text-center"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>