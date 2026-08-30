#!/usr/bin/env bash
#
# TASK-MVP-017 (RISK_REGISTER.md R76, DECISION_LOG.md C92). Canonical
# production deployment step - everything AFTER the host source tree has
# already been updated to the exact commit given as $1 (R65/C76's own
# "host source tree is the deployment's single source of truth" rule -
# unchanged, still a manual tar+scp+sha256-verify step performed BEFORE
# this script runs, never done by this script itself).
#
# Exists specifically to close a real, live incident: every prior
# deployment in this project's history rebuilt/recreated `app` (PHP-FPM)
# alone, via commands typed by hand - `web` (nginx, the container that
# actually SERVES /themes/.../build/... static assets, built via a
# build-time `COPY --from=app` in Dockerfile.production, never a shared
# runtime volume with `app`) went 10 days without being rebuilt, silently
# serving stale JS/CSS until the Admin theme's Vite manifest referenced a
# file `web`'s own stale image never had at all - breaking Vue app-mount
# or every Merchant Admin tenant. No documentation ever explicitly wrote
# "app only" as guidance; the omission of `web` was simply never written
# down as a complete, precise command anywhere - this script is that
# precise command now, replacing operator memory/habit with one canonical,
# hard-to-abbreviate path.
#
# TASK-OPS-019 (RISK_REGISTER.md R77, DECISION_LOG.md C93). Also closes a
# second, later-discovered incident: this script's own repeated use, with
# no disk lifecycle policy at all, left production's root filesystem at
# 94% used (~30GB under /var/lib/docker alone) before a one-time manual
# cleanup (TASK-OPS-018). This version adds, in order: a fail-closed
# pre-build disk gate; a one-deploy-consistent rollback image pair
# (`estore-app:rollback`/`estore-web:rollback` + a `ROLLBACK_COMMIT`
# marker), advanced ONLY after a full success; bounded (age-filtered, not
# unconditional) build-cache/dangling-image cleanup, delegated to the
# shared `docker/production/docker-disk-hygiene.sh` script so the exact
# same cleanup logic is never duplicated between this script and the
# scheduled cron backstop; and a final, authoritative `df`-based disk
# report. None of this changes the R76/C92 app+web-together guarantee
# below, and none of it can ever fail an otherwise-successful, already-
# verified deployment (see the FINAL GATE and rollback-consistency
# sections further down) - see production-deployment.md "Docker disk
# hygiene" for the full design.
#
# Usage:
#   docker/production/deploy.sh <git-commit-sha>
#
# Deliberately does NOT:
#   - sync/update the host source tree (assumed already done, per R65/C76)
#   - roll back on failure (diagnosis/recovery stays a deliberate,
#     separate operator decision - see the FINAL GATE section below)
#   - touch mysql/redis in any way (see --no-deps below)
#   - mutate any tenant data
#   - require or read a real merchant/operator password
#   - automatically USE the rollback pair it maintains - advancing the
#     rollback pointer and actually rolling back to it are two entirely
#     different, never-conflated operations; using it always remains a
#     deliberate, manual, human-verified decision (see the rollback
#     section below for exactly why image rollback alone is not always
#     sufficient - host source and database migrations are not covered)

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <git-commit-sha>" >&2
    exit 1
fi

COMMIT="$1"

if [[ ! "${COMMIT}" =~ ^[0-9a-fA-F]{7,40}$ ]]; then
    echo "Error: '${COMMIT}' does not look like a git commit SHA (expected 7-40 hex characters)." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_FILE="${APP_ROOT}/docker-compose.production.yml"

if [[ ! -f "${COMPOSE_FILE}" ]]; then
    echo "Error: ${COMPOSE_FILE} not found - is this script running from the real deployed host source tree?" >&2
    exit 1
fi

cd "${APP_ROOT}"

# ---------------------------------------------------------------------
# Exclusive lock (RISK_REGISTER.md R77) - held for this script's ENTIRE
# remaining lifetime (auto-released when this process exits, standard
# `flock` behavior; no explicit unlock needed). Verified available on the
# real production host, not assumed (`flock` from `util-linux`, already
# installed - no new package required). Closes a real, non-hypothetical
# race: the scheduled `docker-disk-hygiene.sh` cron backstop's own
# `docker image prune -f` could otherwise sweep the very image THIS
# script is about to preserve as `estore-app:rollback`/`estore-web:
# rollback` - the old `:latest` image becomes briefly dangling the moment
# the build below reassigns that tag, and stays dangling (a legitimate
# target for a narrow, correct prune) until the rollback-pair-advance
# step, potentially MINUTES later (after the full recreate + platform:
# production:check sequence) - a window `docker builder prune`'s own
# BuildKit-internal in-use tracking does NOT protect, since that
# protection only ever covers active build CACHE, never a plain dangling
# IMAGE. Fails closed - never blocks/waits - if the lock is already held
# (another deploy, or a hygiene run already past the point of skipping
# itself - see docker-disk-hygiene.sh's own docblock): a stuck/hung
# holder must never silently wedge every future deploy attempt.
# ---------------------------------------------------------------------
LOCK_FILE="${APP_ROOT}/.docker-deploy.lock"
exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
    echo "Error: another deployment or Docker hygiene run is already in progress (lock: ${LOCK_FILE})." >&2
    echo "Refusing to start - wait for it to finish, then retry." >&2
    exit 1
fi

# ---------------------------------------------------------------------
# Disk pre-flight gate (RISK_REGISTER.md R77). Fail CLOSED, before ANY
# build/recreate mutation, if free space is critically low - a deployment
# must never fill the filesystem halfway through a Docker build. Reads
# the real, authoritative `df` state (never Docker's own reclaimable-byte
# accounting - proven, during TASK-OPS-018's own cleanup, to be an
# unreliable proxy for actual physical disk state after a prune).
#
# Checks the filesystem BACKING DOCKER'S OWN DATA ROOT - resolved from
# Docker itself (`docker info --format '{{.DockerRootDir}}'`), never
# hardcoded as `/var/lib/docker` - because that is where `docker compose
# build`/BuildKit will actually consume storage; on today's production
# topology this happens to be the SAME filesystem as the host source
# tree, but this gate must stay correct if that ever changes. If Docker's
# own data root cannot be resolved or accessed at all, this is treated as
# critical and fails closed - a build must never proceed without knowing
# where its own storage lives. The host source tree's own filesystem is
# ALSO checked, but only as a genuinely separate check when `df` reports
# a different backing device than Docker's data root - avoiding a
# redundant duplicate check (and duplicate warning) on today's
# single-filesystem topology.
#
# 8GB / 92% chosen with roughly 3x headroom over this project's own
# observed worst-case single-build transient footprint (~2-3GB: the
# freshly-built app+web image pair plus their own transient build-cache
# layers, before the previous deploy's own images/cache are cleaned up) -
# conservative without being too tight for this ~83GB disk.
# ---------------------------------------------------------------------
echo "==> Disk pre-flight check"

# check_disk_or_fail <path> <label>: prints `df -h`, warns at >=75%,
# fails the whole script (exit 1) at <8GB free or >92% used. Never used
# for anything but this pre-build gate - docker-disk-hygiene.sh's own
# post-cleanup report is deliberately non-fatal and defined separately.
check_disk_or_fail() {
    local check_path="$1"
    local check_label="$2"
    local disk_line disk_avail_kb disk_use_pct disk_avail_gb

    disk_line="$(df -Pk "${check_path}" 2>/dev/null | tail -1)"
    disk_avail_kb="$(echo "${disk_line}" | awk '{print $4}')"
    disk_use_pct="$(echo "${disk_line}" | awk '{print $5}' | tr -d '%')"

    if [[ ! "${disk_avail_kb}" =~ ^[0-9]+$ ]] || [[ ! "${disk_use_pct}" =~ ^[0-9]+$ ]]; then
        echo >&2
        echo "==> CRITICAL: could not read/parse disk usage for ${check_label} (${check_path})." >&2
        echo "==> Refusing to start a deployment without being able to verify free space there." >&2
        exit 1
    fi

    disk_avail_gb=$(( disk_avail_kb / 1024 / 1024 ))

    echo "==> ${check_label} (${check_path}):"
    df -h "${check_path}"

    if [[ "${disk_avail_gb}" -lt 8 || "${disk_use_pct}" -gt 92 ]]; then
        echo >&2
        echo "==> CRITICAL: refusing to start a deployment - ${check_label} (${check_path}) has ${disk_avail_gb}GB free / ${disk_use_pct}% used." >&2
        echo "==> No build has been attempted. Free disk space manually (see docs/architecture/production-deployment.md \"Docker disk hygiene\"), then retry." >&2
        exit 1
    fi

    if [[ "${disk_use_pct}" -ge 75 ]]; then
        echo "==> WARNING: ${check_label} (${check_path}) at ${disk_use_pct}% (>= 75% informational threshold) BEFORE this deployment even starts - investigate if this persists after cleanup below."
    fi
}

DOCKER_ROOT_DIR="$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || true)"
if [[ -z "${DOCKER_ROOT_DIR}" || ! -d "${DOCKER_ROOT_DIR}" ]]; then
    echo >&2
    echo "==> CRITICAL: could not resolve Docker's own data root directory (\`docker info --format '{{.DockerRootDir}}'\`)." >&2
    echo "==> Refusing to start a deployment without being able to verify the filesystem Docker will actually consume storage on." >&2
    exit 1
fi

check_disk_or_fail "${DOCKER_ROOT_DIR}" "Docker data root"

SOURCE_DEV="$(df -P "${APP_ROOT}" 2>/dev/null | tail -1 | awk '{print $1}')"
DOCKER_DEV="$(df -P "${DOCKER_ROOT_DIR}" 2>/dev/null | tail -1 | awk '{print $1}')"
if [[ -n "${SOURCE_DEV}" && "${SOURCE_DEV}" != "${DOCKER_DEV}" ]]; then
    check_disk_or_fail "${APP_ROOT}" "host source tree"
fi

echo "==> Deploying commit ${COMMIT}"
echo "==> Host source tree: ${APP_ROOT}"
echo "==> (Assumes the host source tree above was already updated to this exact commit via the standard tar+scp+sha256-verify process - this script does not sync source itself.)"
echo

# ---------------------------------------------------------------------
# Capture PRE-deploy state (RISK_REGISTER.md R77, DECISION_LOG.md C93) -
# BEFORE the build reassigns `estore-app:latest`/`estore-web:latest` and
# BEFORE APP_COMMIT is overwritten below. This is what "the deployment
# immediately preceding the one currently being verified" means - not
# merely whatever happens to be untagged/dangling after the build. All
# reads are tolerant of "doesn't exist yet" (a fresh host, or an early
# deploy under this policy) - captured as empty, handled explicitly
# further down, never treated as a script error.
#
# ALSO captures the CURRENTLY-existing rollback record (if any) - the
# `:rollback` pair from the deployment BEFORE this one, about to be
# overwritten by advancing the pointer below. This is what makes it
# possible to restore a previously-valid rollback record if advancing to
# the new one only partially succeeds (see the rollback-advance section
# further down) - the goal is never to end this run with a record that is
# neither the old, known-good one nor a fully-valid new one.
# ---------------------------------------------------------------------
PREV_APP_IMAGE_ID="$(docker image inspect estore-app:latest --format '{{.Id}}' 2>/dev/null || true)"
PREV_WEB_IMAGE_ID="$(docker image inspect estore-web:latest --format '{{.Id}}' 2>/dev/null || true)"
PREV_COMMIT=""
if [[ -f "${APP_ROOT}/APP_COMMIT" ]]; then
    PREV_COMMIT="$(cat "${APP_ROOT}/APP_COMMIT")"
fi

OLD_ROLLBACK_APP_ID="$(docker image inspect estore-app:rollback --format '{{.Id}}' 2>/dev/null || true)"
OLD_ROLLBACK_WEB_ID="$(docker image inspect estore-web:rollback --format '{{.Id}}' 2>/dev/null || true)"
OLD_ROLLBACK_COMMIT=""
if [[ -f "${APP_ROOT}/ROLLBACK_COMMIT" ]]; then
    OLD_ROLLBACK_COMMIT="$(cat "${APP_ROOT}/ROLLBACK_COMMIT")"
fi

echo "==> Writing APP_COMMIT marker"
echo "${COMMIT}" > "${APP_ROOT}/APP_COMMIT"

echo "==> Building app + web together (never one alone - see RISK_REGISTER.md R76)"
docker compose -f "${COMPOSE_FILE}" build app web

# --no-deps: explicitly, deterministically limits this command to ONLY the
# two services named - mysql/redis are declared as `depends_on` for `app`/
# `web` in docker-compose.production.yml, and WITHOUT --no-deps, `up`
# could start (though not recreate) a dependency that happened not to be
# running yet. --no-deps removes that ambiguity entirely: mysql/redis are
# never started, recreated, or otherwise touched by this command,
# regardless of their current state. Verified directly against Docker
# Compose's own documented flag semantics ("Don't start linked services")
# and, separately, against this exact production compose file during this
# project's own real incident recovery (`up -d --force-recreate --no-deps
# web` left app/mysql/redis's own uptimes completely unchanged).
echo "==> Recreating app + web together (mysql/redis are never touched - see --no-deps above)"
docker compose -f "${COMPOSE_FILE}" up -d --force-recreate --no-deps app web

echo "==> Clearing application caches"
docker compose -f "${COMPOSE_FILE}" exec -T app php artisan config:clear
docker compose -f "${COMPOSE_FILE}" exec -T app php artisan route:clear
docker compose -f "${COMPOSE_FILE}" exec -T app php artisan view:clear

# FINAL GATE, explicit product-owner decision: platform:production:check
# is the deployment's own final verification. A non-zero exit here means
# this script ALSO exits non-zero and reports failure clearly - a failed
# production check is never silently treated as a successful deployment.
# Deliberately NO automatic rollback/restore here: the application source
# and images have already been updated by this point (that part cannot be
# silently undone without risking a worse, half-reverted state) - a real
# failure here needs a human to look at the actual `platform:production:
# check` output above and decide the right next step, not a script
# guessing at one. Everything below this gate - rollback-pair advancement,
# cache/image cleanup - is ONLY ever reached after a full, verified
# success; on failure, the PREVIOUS rollback pair (if any) is left
# completely untouched, exactly as it was before this run.
echo "==> Running platform:production:check (final deployment gate)"
echo
if ! docker compose -f "${COMPOSE_FILE}" exec -T app php artisan platform:production:check; then
    echo
    echo "==> DEPLOYMENT FAILED." >&2
    echo "==> The application source/images/containers have already been updated to commit ${COMMIT}," >&2
    echo "==> but platform:production:check did NOT pass - production readiness is NOT confirmed." >&2
    echo "==> This script does not roll back automatically. Review the check output above," >&2
    echo "==> diagnose the specific failing row, and decide the correct recovery step manually." >&2
    exit 1
fi

echo
echo "==> Deployment of ${COMMIT} succeeded and passed production readiness verification."

# ---------------------------------------------------------------------
# Rollback pair advancement (RISK_REGISTER.md R77, DECISION_LOG.md C93) -
# ONLY reached after full success above. Retags the PRE-deploy images
# (captured before the build ran) as estore-app:rollback/estore-web:
# rollback, so the pointer always means "the deployment immediately
# preceding this now-verified one" - never "whatever was merely untagged
# by the build." A `docker tag` onto an already-used tag name simply
# moves it; the image the OLD rollback tag pointed at becomes dangling as
# a deterministic side effect and is swept by this same run's own
# dangling-image cleanup further below - so exactly one rollback pair
# ever exists, walking back by exactly one deploy, never accumulating.
#
# Failure here is treated as a WARNING, never as deployment failure: the
# primary, verified deployment has already succeeded by this point: a
# rollback-bookkeeping problem is a safety-net gap, not a production
# health problem, and must never make an already-correct, already-
# verified system get reported as failed. But the record is treated
# defensively (DECISION_LOG.md C93): all three pieces of the rollback
# record (app image, web image, ROLLBACK_COMMIT) are re-read and compared
# against what was actually captured before the build, and a PARTIAL
# update never lingers - either the new record ends up fully valid, or a
# best-effort restore puts the PREVIOUSLY-valid record (captured before
# this run touched anything) back, so the operator is never left with an
# inconsistent mix of old-app/new-web (or similar) without being told
# plainly which state actually exists.
#
# ROLLBACK_COMMIT is written via a temp-file-then-rename (same directory,
# so the rename stays on one filesystem and is atomic) - never a direct
# overwrite - so the file is never observed half-written if something
# fails mid-write (e.g. the disk pre-flight gate above already reduces
# this risk, but the write itself stays defensive regardless).
#
# Image rollback is a fast, first-response tool for the narrow case of
# "the new code/assets themselves are broken and no schema-affecting
# migration ran in this deploy" - it does NOT revert the host source tree
# (a separate R65/C76 process) or database migrations (never reversible
# by any tooling in this project). Any actual USE of the rollback pair
# must remain a deliberate, manual, human-verified decision that first
# confirms no incompatible migration ran - never automatic, and this
# script never performs it.
# ---------------------------------------------------------------------

# write_rollback_commit_or_fail <value>: atomic temp-file-then-rename
# write of ROLLBACK_COMMIT. Returns non-zero (never exits/aborts the
# script itself) on any failure, leaving any pre-existing file untouched.
write_rollback_commit_or_fail() {
    local value="$1"
    local tmp
    tmp="$(mktemp "${APP_ROOT}/.ROLLBACK_COMMIT.tmp.XXXXXX" 2>/dev/null)" || return 1
    if ! echo "${value}" > "${tmp}" 2>/dev/null; then
        rm -f "${tmp}"
        return 1
    fi
    if ! mv -f "${tmp}" "${APP_ROOT}/ROLLBACK_COMMIT" 2>/dev/null; then
        rm -f "${tmp}"
        return 1
    fi
}

# apply_rollback_record <app_id> <web_id> <commit> <what_label>: tags
# both images and writes ROLLBACK_COMMIT for the given values, then
# re-reads all three and reports (via return code) whether they now
# match exactly what was requested. Used for BOTH the forward advance
# (to the new pre-deploy pair) and, if that fails, the restore attempt
# (back to the old pair) - identical logic, just different inputs/labels.
apply_rollback_record() {
    local want_app_id="$1"
    local want_web_id="$2"
    local want_commit="$3"
    local what_label="$4"

    docker tag "${want_app_id}" estore-app:rollback 2>/dev/null \
        || echo "==> WARNING: failed to tag estore-app:rollback (${what_label})" >&2
    docker tag "${want_web_id}" estore-web:rollback 2>/dev/null \
        || echo "==> WARNING: failed to tag estore-web:rollback (${what_label})" >&2
    write_rollback_commit_or_fail "${want_commit}" \
        || echo "==> WARNING: failed to write ROLLBACK_COMMIT (${what_label})" >&2

    local got_app_id got_web_id got_commit
    got_app_id="$(docker image inspect estore-app:rollback --format '{{.Id}}' 2>/dev/null || true)"
    got_web_id="$(docker image inspect estore-web:rollback --format '{{.Id}}' 2>/dev/null || true)"
    got_commit=""
    if [[ -f "${APP_ROOT}/ROLLBACK_COMMIT" ]]; then
        got_commit="$(cat "${APP_ROOT}/ROLLBACK_COMMIT")"
    fi

    [[ "${got_app_id}" == "${want_app_id}" && "${got_web_id}" == "${want_web_id}" && "${got_commit}" == "${want_commit}" ]]
}

echo
echo "==> Advancing rollback pair (records the deployment immediately preceding this one)"

if [[ -n "${PREV_APP_IMAGE_ID}" && -n "${PREV_WEB_IMAGE_ID}" ]]; then
    if apply_rollback_record "${PREV_APP_IMAGE_ID}" "${PREV_WEB_IMAGE_ID}" "${PREV_COMMIT}" "advancing to new pair"; then
        echo "==> Rollback pair confirmed: estore-app:rollback / estore-web:rollback / ROLLBACK_COMMIT=${PREV_COMMIT}"
    else
        echo >&2
        echo "==> WARNING: advancing the rollback record to the new pair FAILED or produced an inconsistent result." >&2
        if [[ -n "${OLD_ROLLBACK_APP_ID}" && -n "${OLD_ROLLBACK_WEB_ID}" ]]; then
            echo "==> Attempting a best-effort restore of the PREVIOUSLY-valid rollback record..." >&2
            if apply_rollback_record "${OLD_ROLLBACK_APP_ID}" "${OLD_ROLLBACK_WEB_ID}" "${OLD_ROLLBACK_COMMIT}" "restoring previous pair"; then
                echo "==> Previous rollback record restored successfully: estore-app:rollback / estore-web:rollback / ROLLBACK_COMMIT=${OLD_ROLLBACK_COMMIT}" >&2
                echo "==> (This means the rollback pointer still points at an OLDER deployment than the one just completed - not this one.)" >&2
            else
                echo "==> Restore ALSO failed. ROLLBACK METADATA IS INCONSISTENT/UNAVAILABLE." >&2
                echo "==> Do NOT trust estore-app:rollback/estore-web:rollback/ROLLBACK_COMMIT until manually re-verified." >&2
            fi
        else
            echo "==> No previous rollback record existed to restore (expected on an early deploy under this policy)." >&2
            echo "==> ROLLBACK METADATA IS UNAVAILABLE after this run - do not trust it until manually established." >&2
        fi
        echo "==> None of this affects the success of THIS deployment (commit ${COMMIT} is deployed and verified) -" >&2
        echo "==> it only means the one-step-back manual safety net may be stale, missing, or older than expected." >&2
    fi
else
    echo "==> No prior estore-app:latest/estore-web:latest found - nothing to preserve as a rollback pair."
    echo "==> (Expected on the very first deploy run under this policy; not an error.)"
fi

# ---------------------------------------------------------------------
# Bounded Docker disk cleanup (RISK_REGISTER.md R77, DECISION_LOG.md C93).
# Delegated to the shared docker-disk-hygiene.sh script (also run
# independently by the scheduled cron backstop - see production-
# deployment.md) so the exact same age-bounded build-cache prune and
# narrow dangling-image prune are never duplicated/drifted between the
# two call sites. Runs AFTER the rollback pair above is already safely
# tagged, so it can never be swept by the dangling-image prune. Always
# best-effort - see the script's own docblock for why it never fails this
# deployment's own exit status.
#
# DOCKER_HYGIENE_LOCK_HELD=1 tells the child script THIS process already
# holds the exclusive lock (acquired above, for this script's entire
# run) - it must not try to acquire it again itself. `flock`'s lock is
# tied to a specific open file description, not merely a pathname or
# process, so a naive child-side `exec 9>"${LOCK_FILE}"; flock -n 9`
# would open a genuinely NEW file description on the same path and then
# correctly (but unwantedly) report a conflict against the parent's own
# already-held lock - this flag avoids that self-deadlock deliberately,
# rather than relying on fd-inheritance behavior being exactly right.
# ---------------------------------------------------------------------
echo
DOCKER_HYGIENE_LOCK_HELD=1 "${SCRIPT_DIR}/docker-disk-hygiene.sh" || echo "==> WARNING: docker-disk-hygiene.sh reported an error - continuing (non-fatal, housekeeping only)" >&2

echo
echo "==> Deployment of ${COMMIT} is complete."
