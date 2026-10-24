#!/bin/sh
set -eu

cd /var/www/html

mkdir -p storage/app storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache

# Bind mounts retain host ownership, including after changing image UID/GID.
chown -R www-data:www-data storage bootstrap/cache
chmod -R u+rwX storage bootstrap/cache

echo "Laravel writable-directory permissions initialized."
