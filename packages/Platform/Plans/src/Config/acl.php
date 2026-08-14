<?php

declare(strict_types=1);

/**
 * TASK-ARCH-009. Merged into the same 'acl' config key
 * Webkul\Admin\Providers\AdminServiceProvider merges its own
 * Config/acl.php into. One ACL key per menu key above, `route` matching
 * this package's own admin route name - Webkul\User\Http\Middleware\
 * Bouncer::checkIfAuthorized() reads config('acl') via Webkul\Core\Acl::
 * getRoles() (route-name -> acl-key map) and abort(401)s any admin route
 * with no matching entry, so this is not optional: without it,
 * admin.saas.plan.index would be unreachable by every role, including
 * the superadmin's 'all' permission_type (see Bouncer::isPermissionsEmpty()
 * - only a permission_type='all' role skips the per-route ACL check
 * entirely; a scoped role still needs this key granted explicitly).
 */
return [
    [
        'key' => 'saas',
        'name' => 'SaaS',
        'route' => 'admin.saas.plan.index',
        'sort' => 10,
    ], [
        'key' => 'saas.plan',
        'name' => 'My Plan',
        'route' => 'admin.saas.plan.index',
        'sort' => 1,
    ],
];
