<?php

declare(strict_types=1);

namespace Platform\Tenancy\Support;

/**
 * TASK-OPS-MONITORING-001. The structured, machine-readable result of one
 * `platform:production:check` row - what `ProductionReadinessCheck::
 * collectResults()` returns an array of. Every `resultPass()`/`resultWarn()`/
 * `resultFail()`/`resultInfo()` call already built exactly this shape
 * inline (a `[check, coloredStatus, detail]` tuple only `handle()`'s own
 * `$this->table()` call ever consumed); this class gives that same data a
 * real type instead of a positional array, so `Platform\Tenancy\Services\
 * ProductionMonitorRunner` (or any future consumer) can read `->status` as
 * a real `ReadinessStatus` enum rather than parsing console color tags.
 *
 * Deliberately still owns the console-rendering concern (`consoleStatus()`/
 * `toTableRow()`) rather than splitting it into a second class - this IS
 * the one and only place `platform:production:check`'s row shape is
 * defined, and `handle()`'s own `$this->table()` call is still the only
 * caller of `toTableRow()`. No behavior changed: the rendered console
 * table is byte-for-byte identical to before this class existed.
 */
final class ReadinessCheckResult
{
    public function __construct(
        public readonly string $check,
        public readonly ReadinessStatus $status,
        public readonly string $detail,
    ) {}

    /**
     * The exact Symfony Console formatting tag every existing check has
     * always returned (`<info>PASS</info>`, `<comment>WARN</comment>`,
     * `<error>FAIL</error>`, bare `INFO`) - unchanged strings, now derived
     * from the typed status instead of being hand-written at each of the
     * 19 call sites.
     */
    public function consoleStatus(): string
    {
        return match ($this->status) {
            ReadinessStatus::Pass => '<info>PASS</info>',
            ReadinessStatus::Warn => '<comment>WARN</comment>',
            ReadinessStatus::Fail => '<error>FAIL</error>',
            ReadinessStatus::Info => 'INFO',
        };
    }

    /** @return array{0: string, 1: string, 2: string} */
    public function toTableRow(): array
    {
        return [$this->check, $this->consoleStatus(), $this->detail];
    }
}
