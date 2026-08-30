#!/usr/bin/env bash
#
# TASK-OPS-019 (RISK_REGISTER.md R77, DECISION_LOG.md C93). Bounded Docker
# disk hygiene - shared by BOTH docker/production/deploy.sh (run once,
# automatically, immediately after every successful deployment) AND a
# root-crontab backstop (run independently, on a fixed daily schedule, so
# growth stays bounded even if deploy.sh is ever bypassed - the same
# "undocumented habit" failure mode TASK-MVP-017/R76 closed for the
# app/web-together problem, applied here to disk hygiene). See
# docs/architecture/production-deployment.md "Docker disk hygiene" for the
# full design and the exact production crontab installation step.
#
# Deliberately does NOT touch:
#   - estore-app:rollback / estore-web:rollback (or any other tagged image)
#   - mysql/redis images, containers, or their bind-mounted data
#   - Docker volumes (never invoked, never `docker system prune --volumes`)
#   - unrelated tagged images already on the host
# Never runs `docker system prune` or `docker image prune -a`.
#
# Usage:
#   docker/production/docker-disk-hygiene.sh                (standalone/cron)
#   DOCKER_HYGIENE_LOCK_HELD=1 docker/production/docker-disk-hygiene.sh
#                                                             (called by deploy.sh)
#
# All output goes to stdout/stderr - the caller decides where to redirect
# it (deploy.sh's own session log, or the dedicated cron log documented in
# production-deployment.md - deliberately never the backup cron's own log
# file, so the two concerns stay independently inspectable). This script
# is intentionally best-effort: every step below can fail without aborting
# the rest of it, and the script itself always exits 0 - it is never a
# gate on anything else. deploy.sh and cron both treat it as housekeeping,
# never as a correctness check.

set -uo pipefail
# Deliberately NOT `set -e` (unlike deploy.sh) - see the docblock above.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# ---------------------------------------------------------------------
# Concurrency guard (RISK_REGISTER.md R77). Same lock file deploy.sh
# itself acquires and holds for its ENTIRE run (build through this same
# cleanup). Closes a real race: an independently-scheduled cron run of
# this exact script, firing while a deployment is mid-flight, could
# otherwise sweep the very image deploy.sh is about to preserve as
# `estore-app:rollback`/`estore-web:rollback` (that image is briefly
# dangling between the build reassigning `:latest` and deploy.sh's own
# later rollback-tag-advance step, possibly minutes later) - a window
# `docker builder prune`'s own BuildKit-internal in-use tracking does NOT
# protect, since that only ever covers active build cache, never a plain
# dangling image.
#
# When called BY deploy.sh (DOCKER_HYGIENE_LOCK_HELD=1), the parent
# already holds this lock for its whole run - this script must NOT try
# to acquire it again itself: `flock`'s lock is tied to a specific OPEN
# FILE DESCRIPTION, not merely a pathname, so a fresh `exec 9>...` here
# would open a genuinely new file description on the same path and then
# correctly (but unwantedly) report a conflict against the parent's own
# lock, self-deadlocking. When run STANDALONE (cron), it acquires the
# lock itself, non-blocking - if a deployment is currently in progress
# (holding it), this run safely, harmlessly skips itself (exit 0, not an
# error - deploy.sh's own end-of-run call to this same script already
# covers the immediate cleanup need for that deployment; the next
# scheduled cron cycle covers everything else).
# ---------------------------------------------------------------------
LOCK_FILE="${APP_ROOT}/.docker-deploy.lock"
if [[ "${DOCKER_HYGIENE_LOCK_HELD:-0}" != "1" ]]; then
    exec 9>"${LOCK_FILE}"
    if ! flock -n 9; then
        echo "==> [docker-disk-hygiene] Another deployment or hygiene run is already in progress - skipping this run (safe; will run again next scheduled cycle)."
        exit 0
    fi
fi

# 7 days: conservative, matches this project's own observed deploy cadence
# (roughly daily during active periods, per RISK_REGISTER.md R77's own
# evidence) - long enough that two deploys within the same active window
# still get a real dependency-install cache hit (see this task's own
# Dockerfile.production fix), short enough that cache from an abandoned or
# quiet period never lingers indefinitely. `--filter "until=<duration>"`
# was verified directly, not guessed, against this project's actual
# Docker/BuildKit version: a real local before/after test (TASK-OPS-019's
# own report) proved it (a) is valid, accepted syntax, (b) filters by each
# cache entry's own LAST ACCESSED time, not creation time, and (c) never
# touches cache still backing an existing image/build regardless of age -
# only genuinely unshared, stale cache is ever reclaimed.
BUILD_CACHE_RETENTION="168h"

echo "==> [docker-disk-hygiene] $(date -u +%Y-%m-%dT%H:%M:%SZ) starting"

echo "==> [docker-disk-hygiene] Pruning build cache older than ${BUILD_CACHE_RETENTION} (bounded, never system/volume prune - see RISK_REGISTER.md R77)"
if ! docker builder prune -f --filter "until=${BUILD_CACHE_RETENTION}"; then
    echo "==> [docker-disk-hygiene] WARNING: build cache prune reported an error - continuing" >&2
fi

echo "==> [docker-disk-hygiene] Pruning dangling images (narrow, never -a, never touches a tagged/in-use image)"
if ! docker image prune -f; then
    echo "==> [docker-disk-hygiene] WARNING: dangling image prune reported an error - continuing" >&2
fi

# ---------------------------------------------------------------------
# Disk report (RISK_REGISTER.md R77) - df is authoritative, not docker's
# own reclaimable-byte accounting (directly observed, during TASK-OPS-018's
# own cleanup, to re-inflate after a real prune while `df` stayed flat).
# Reports the filesystem backing DOCKER'S OWN DATA ROOT first - resolved
# from Docker itself, never hardcoded as `/var/lib/docker` - since that is
# what the prune commands above actually just acted on. The host source
# tree's own filesystem is also reported, but only as a genuinely separate
# line when `df` shows a different backing device, avoiding a redundant
# duplicate report on today's single-filesystem topology. Always
# informational only - never fails this script, matching its own
# best-effort design.
# ---------------------------------------------------------------------
report_disk() {
    local check_path="$1"
    local check_label="$2"
    local disk_line disk_use_pct

    echo "==> [docker-disk-hygiene] ${check_label} (${check_path}):"
    df -h "${check_path}" 2>/dev/null || echo "==> [docker-disk-hygiene] WARNING: could not read disk usage for ${check_label} (${check_path})" >&2

    disk_line="$(df -Pk "${check_path}" 2>/dev/null | tail -1)"
    disk_use_pct="$(echo "${disk_line}" | awk '{print $5}' | tr -d '%')"
    if [[ "${disk_use_pct}" =~ ^[0-9]+$ ]] && [[ "${disk_use_pct}" -ge 75 ]]; then
        echo "==> [docker-disk-hygiene] WARNING: ${check_label} (${check_path}) at ${disk_use_pct}% (>= 75% informational threshold) even after cleanup - investigate manually (see RISK_REGISTER.md R77)." >&2
    fi
}

DOCKER_ROOT_DIR="$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || true)"
if [[ -n "${DOCKER_ROOT_DIR}" && -d "${DOCKER_ROOT_DIR}" ]]; then
    report_disk "${DOCKER_ROOT_DIR}" "Docker data root"

    SOURCE_DEV="$(df -P "${APP_ROOT}" 2>/dev/null | tail -1 | awk '{print $1}')"
    DOCKER_DEV="$(df -P "${DOCKER_ROOT_DIR}" 2>/dev/null | tail -1 | awk '{print $1}')"
    if [[ -n "${SOURCE_DEV}" && "${SOURCE_DEV}" != "${DOCKER_DEV}" ]]; then
        report_disk "${APP_ROOT}" "host source tree"
    fi
else
    echo "==> [docker-disk-hygiene] WARNING: could not resolve Docker's own data root directory - reporting host source tree filesystem only." >&2
    report_disk "${APP_ROOT}" "host source tree"
fi

echo "==> [docker-disk-hygiene] $(date -u +%Y-%m-%dT%H:%M:%SZ) done"
