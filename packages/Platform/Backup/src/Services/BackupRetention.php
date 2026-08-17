<?php

declare(strict_types=1);

namespace Platform\Backup\Services;

use DateTimeImmutable;

/**
 * TASK-MVP-003A. Pure selection logic for `platform:backup:cleanup` - given
 * the set of finalized backup directory names that currently exist (each
 * named `Y-m-d_His`, `BackupRunner::run()`'s own timestamp format - lexically
 * sortable in exactly chronological order, so no date-parsing is needed to
 * sort or compare them), returns the subset older than the retention window
 * that should be deleted.
 *
 * Deliberately has ZERO filesystem/shell dependency - takes plain strings,
 * returns plain strings - specifically so it can be unit-tested with fake
 * names and a fake "now" per this task's own instruction, without needing
 * real backup directories on disk. The actual deletion (and the
 * `BackupPathGuard` safety check before it) happens in the console command,
 * never here.
 *
 * SAFETY RULE (never violated regardless of retention_days/now): the single
 * newest backup by name is NEVER selected for deletion, even if its own
 * timestamp is somehow older than the cutoff (a clock skew, a manually
 * copied-in old-dated directory, or `retention_days` misconfigured to 0) -
 * a successful backup run must never leave zero backups behind.
 */
class BackupRetention
{
    public function __construct(protected int $retentionDays)
    {
    }

    /**
     * @param  array<int, string>  $backupNames  directory basenames, e.g. '2026-08-17_211530'
     * @return array<int, string> the subset that should be deleted
     */
    public function selectForDeletion(array $backupNames, DateTimeImmutable $now): array
    {
        $names = array_values(array_unique($backupNames));

        if (count($names) <= 1) {
            return [];
        }

        sort($names);

        $newest = $names[array_key_last($names)];
        $cutoff = $now->modify('-'.max(0, $this->retentionDays).' days')->format('Y-m-d_His');

        return array_values(array_filter(
            $names,
            fn (string $name): bool => $name !== $newest && $name < $cutoff
        ));
    }
}
