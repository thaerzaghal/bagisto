<?php

declare(strict_types=1);

/**
 * TASK-ARCH-019. Merged into the same 'acl' config key
 * `Platform\Plans\Providers\PlansServiceProvider` (and
 * `Webkul\Admin\Providers\AdminServiceProvider`) merge their own
 * Config/acl.php into. One key for the whole checkout flow - `'route'` is
 * an array (`Webkul\Core\Acl::getRoles()` supports this via `Arr::wrap()`),
 * so all four checkout routes share one permission grant rather than
 * needing four separate ACL keys for what is one feature. Without this,
 * every route below would 401 for any role that is not `permission_type
 * = 'all'` - see `Webkul\User\Http\Middleware\Bouncer::checkIfAuthorized()`.
 */
return [
    [
        'key' => 'saas.checkout',
        'name' => 'Checkout',
        'route' => [
            'admin.saas.checkout.index',
            'admin.saas.checkout.store',
            'admin.saas.checkout.success',
            'admin.saas.checkout.cancel',
        ],
        'sort' => 2,
    ],
];
