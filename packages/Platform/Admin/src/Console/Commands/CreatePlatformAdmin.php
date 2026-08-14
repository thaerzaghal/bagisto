<?php

declare(strict_types=1);

namespace Platform\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Platform\Admin\Models\PlatformUser;

/**
 * TASK-ARCH-011. The ONLY supported way to create a platform admin - there
 * is deliberately no seeder and no default/known credential shipped in
 * source control (see docs/architecture/platform-admin.md, "Bootstrap").
 *
 * Works both interactively (`php artisan platform:admin:create`, prompts
 * for each field, password input hidden via `secret()`) and non-
 * interactively/parameterized (`--name=... --email=... --password=...`,
 * for scripted deployment bootstrap) - the same dual-mode shape as
 * Bagisto's own `bagisto:install` prompts, without touching that command.
 *
 * `PlatformUser` uses `CentralConnection` (see that model's docblock), so
 * this command is central-only by construction - it never depends on, or
 * can be accidentally run against, any tenant database.
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:admin:create
        {--name= : Platform admin name}
        {--email= : Platform admin email}
        {--password= : Platform admin password}';

    protected $description = 'Create a platform admin (central-only, no default credentials).';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Password');

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (PlatformUser::where('email', $email)->exists()) {
            $this->error("A platform admin with email [{$email}] already exists.");

            return self::FAILURE;
        }

        PlatformUser::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Platform admin [{$email}] created.");

        return self::SUCCESS;
    }
}
