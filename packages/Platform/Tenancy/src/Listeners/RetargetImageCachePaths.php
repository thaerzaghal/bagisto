<?php

declare(strict_types=1);

namespace Platform\Tenancy\Listeners;

/**
 * TASK-ARCH-005 (RISK_REGISTER.md R16, new finding): Webkul\ImageCache's
 * config/imagecache.php ships:
 *
 *     'paths' => [storage_path('app/public'), public_path('storage')],
 *
 * Config files are evaluated once at application boot, long before tenancy
 * ever initializes - so this array is permanently frozen to the CENTRAL,
 * non-tenant-suffixed storage_path(), for the lifetime of the process. Once
 * Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper suffixes
 * storage_path() per tenant, Webkul\ImageCache\Http\Controllers\
 * ImageCacheController::getImagePath() (the /cache/{template}/{filename}
 * resize route) would keep looking in the wrong, central directory for
 * every tenant, since it checks config('imagecache.paths') BEFORE its own
 * (otherwise-correct) storage_path()-based fallback.
 *
 * This is the exact same class of bug as R17 (TASK-ARCH-002): a config
 * value computed from a tenancy-aware helper, baked in too early. The fix
 * is the same shape too: recompute it ourselves, from our own listener,
 * once tenancy (including FilesystemTenancyBootstrapper) has actually
 * finished bootstrapping - zero packages/Webkul changes.
 */
class RetargetImageCachePaths
{
    public function bootstrapped(): void
    {
        config(['imagecache.paths' => [
            storage_path('app/public'),
            public_path('storage'),
        ]]);
    }

    public function reverted(): void
    {
        config(['imagecache.paths' => [
            storage_path('app/public'),
            public_path('storage'),
        ]]);
    }
}
