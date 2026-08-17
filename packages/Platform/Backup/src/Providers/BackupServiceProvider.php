<?php

declare(strict_types=1);

namespace Platform\Backup\Providers;

use Illuminate\Support\ServiceProvider;
use Platform\Backup\Console\Commands\CleanupBackups;
use Platform\Backup\Console\Commands\RunBackup;
use Platform\Backup\Services\BackupPathGuard;

/**
 * TASK-MVP-003A. Registers `platform:backup:run`/`platform:backup:cleanup`
 * only - no routes, no middleware, no listeners. `config/platform-backup.php`
 * needs no explicit `mergeConfigFrom()` here; it already lives directly in
 * the application's own root `config/` directory (the same convention
 * `config/platform-billing.php`/`config/platform.php` already use), which
 * Laravel's own config loader discovers automatically.
 */
class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // BackupPathGuard's own constructor takes a plain string, which
        // Laravel's auto-wiring cannot resolve on its own - both
        // BackupRunner and CleanupBackups depend on it, so it is bound
        // here, once, always built from config('platform-backup.root') at
        // the moment it is actually resolved (not cached at boot time),
        // so a runtime config() override (as this package's own tests do)
        // is correctly picked up.
        $this->app->bind(BackupPathGuard::class, fn () => BackupPathGuard::fromConfig());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RunBackup::class,
                CleanupBackups::class,
            ]);
        }
    }
}
