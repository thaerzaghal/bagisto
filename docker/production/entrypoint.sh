#!/bin/sh
# TASK-MVP-004B. Runs as root (see Dockerfile.production's own comment on
# why USER www-data is deliberately NOT set at the container level) - its
# only job is to make storage/ safe to use before handing off to php-fpm,
# which then drops its own worker processes to www-data via the pool
# config, same as the official upstream image.
#
# storage/ is bind-mounted from the host (/opt/estore/storage) so tenant
# uploads/image-cache survive container recreation - but a bind mount over
# a directory that had content baked into the IMAGE (the framework
# skeleton: framework/cache, framework/sessions, framework/views, logs)
# HIDES that image content the moment the mount happens, per Laravel's own
# runtime expectation that those directories already exist. This recreates
# exactly that skeleton on every container start - idempotent, safe to run
# whether storage/ is a fresh empty host directory or already has real
# tenant/framework data in it from a previous run.
set -e

STORAGE=/var/www/html/storage

mkdir -p \
    "$STORAGE/app/public" \
    "$STORAGE/app/private" \
    "$STORAGE/framework/cache/data" \
    "$STORAGE/framework/sessions" \
    "$STORAGE/framework/testing" \
    "$STORAGE/framework/views" \
    "$STORAGE/logs"

# 775/www-data:www-data, never 777 - the framework and any future tenant
# subtree FilesystemTenancyBootstrapper creates under this same mounted
# root both only ever need www-data (PHP-FPM's own worker user) to read
# and write; group-writable (not world-writable) is sufficient since
# nothing outside this container's own PHP-FPM process needs to write here.
chown -R www-data:www-data "$STORAGE"
find "$STORAGE" -type d -exec chmod 775 {} +
find "$STORAGE" -type f -exec chmod 664 {} +

exec "$@"
