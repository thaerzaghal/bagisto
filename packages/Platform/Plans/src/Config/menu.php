<?php

declare(strict_types=1);

/**
 * TASK-ARCH-009. Merged into the same 'menu.admin' config key Webkul\
 * Admin\Providers\AdminServiceProvider merges its own Config/menu.php
 * into (packages/Webkul/Admin/src/Config/menu.php:mergeConfigFrom -
 * plain list-array append semantics, confirmed via
 * packages/Webkul/Core/src/Menu.php reading config('menu.admin') and
 * packages/Webkul/Core/tests/Unit/MenuTest.php's own test pattern). Same
 * key/name/route/sort/icon shape as every Webkul menu entry - no
 * separate menu system invented. `name` is a plain literal string, not a
 * translation key: Webkul\Core\Menu::getItems() wraps every name in
 * trans(), and Laravel's trans() returns a string unchanged when no
 * matching translation key exists - correct, minimal, no lang file
 * needed for four words (task instruction: smallest maintainable
 * approach, not a localization framework).
 *
 * 'icon' reuses 'icon-settings' (a real, already-used Bagisto admin icon
 * class - packages/Webkul/Admin/src/Config/menu.php's own 'settings'
 * entry) rather than inventing a new icon class this project would need
 * to also ship CSS for.
 */
return [
    [
        'key' => 'saas',
        'name' => 'SaaS',
        'route' => 'admin.saas.plan.index',
        'sort' => 10,
        'icon' => 'icon-settings',
    ], [
        'key' => 'saas.plan',
        'name' => 'My Plan',
        'route' => 'admin.saas.plan.index',
        'sort' => 1,
        'icon' => '',
    ],
];
