<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-008 cleanup round (RISK_REGISTER.md R31). One-time, idempotent,
 * central-only backfill: before this project added `Tenant::
 * getCustomColumns()`, `status`/`last_error`/`plan_id` were silently
 * written into the `data` JSON blob only (see that fix's docblock) -
 * meaning any tenant row created/last-saved before the fix has stale
 * (or, for plan_id, always-NULL) real columns even though the correct
 * value has been sitting in `data` the whole time.
 *
 * Reads via the real `Platform\Tenancy\Models\Tenant` model rather than
 * hand-parsing the `data` JSON column with raw SQL: loading a row via
 * Eloquent already runs `Stancl\VirtualColumn\VirtualColumn`'s own
 * `retrieved` listener, which decodes `data` back into normal attributes
 * using the exact same logic every other read in this app already trusts
 * (including "a key in `data` wins over a stale real column, a key ABSENT
 * from `data` leaves the real column/NULL alone" - precisely the "read
 * from data when present" / "preserve NULL semantics" requirements this
 * backfill needs).
 *
 * WRITES via a plain, targeted `DB::table('tenants')->update()` -
 * deliberately NOT `$tenant->save()`/`saveQuietly()`. Found live while
 * writing this migration's own regression test: `decodeVirtualColumn()`
 * calls `syncOriginalAttribute($key)` for every key it decodes from
 * `data`, which marks that attribute as "not dirty" relative to the very
 * value it was just decoded to - correct for genuinely virtual
 * attributes (their real backing IS `data`, nothing else to write), but
 * wrong for status/last_error/plan_id now that they're real columns:
 * `$tenant->save()` would see them as unchanged and omit them from its
 * UPDATE entirely, silently leaving the real column exactly as stale as
 * before. A raw, explicit UPDATE sidesteps Eloquent's dirty-tracking for
 * these three columns completely - the correct (already-decoded) values
 * are read via the trusted Eloquent path, then written with no
 * dirty-check in between. This also means `data` itself is left
 * completely untouched (any now-redundant duplicate status/last_error/
 * plan_id keys inside it are neither read again nor removed) - a
 * deliberate simplification once the write no longer goes through
 * `encodeAttributes()`, and a closer match to "does not remove unrelated
 * JSON data" than the original save()-based design attempted.
 *
 * Idempotent/safe to rerun: re-applying the same correct value is a
 * harmless UPDATE, never an incorrect overwrite. Central-only:
 * `Tenant::all()` (CentralConnection trait) and the explicit
 * `DB::connection('mysql')` write never touch any tenant database.
 *
 * Kept as a real, permanent migration (not a one-off tinker/console
 * script) specifically so any OTHER installation of this codebase that
 * happens to carry pre-getCustomColumns-fix tenant rows self-heals
 * automatically on its next `platform:migrate:central` run, without
 * needing to know this bug or this fix ever existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Tenant::all()->each(function (Tenant $tenant) {
            DB::connection('mysql')->table('tenants')->where('id', $tenant->getKey())->update([
                'status' => $tenant->status->value,
                'last_error' => $tenant->last_error,
                'plan_id' => $tenant->plan_id,
            ]);
        });
    }

    /**
     * Intentionally irreversible: this is a forward-only data correction,
     * not a schema change. "Undoing" it would mean re-corrupting rows
     * that are now correct back to their stale state, which has no
     * legitimate use.
     */
    public function down(): void
    {
        //
    }
};
