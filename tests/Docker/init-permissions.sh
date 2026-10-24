#!/bin/sh
set -eu

cd /var/www/html
mkdir -p storage/framework/cache/data bootstrap/cache
printf 'existing cache\n' > bootstrap/cache/config.php
printf 'existing media\n' > storage/framework/cache/data/existing
printf 'application code\n' > application-sentinel
chmod 400 bootstrap/cache/config.php storage/framework/cache/data/existing
chmod 500 storage/framework/cache/data bootstrap/cache

/usr/local/bin/init-permissions
/usr/local/bin/init-permissions

test "$(stat -c %u application-sentinel)" = 0
test "$(cat bootstrap/cache/config.php)" = "existing cache"
test "$(cat storage/framework/cache/data/existing)" = "existing media"
test "$(stat -c %u bootstrap/cache/config.php)" = "$(id -u www-data)"
test "$(stat -c %g bootstrap/cache/config.php)" = "$(id -g www-data)"

su -s /bin/sh www-data -c '
    set -eu
    cd /var/www/html
    for directory in storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
        test -w "$directory"
        printf "writable\n" > "$directory/permission-check"
    done
    printf "updated cache\n" > bootstrap/cache/config.php
    printf "updated data\n" > storage/framework/cache/data/existing
    test ! -w application-sentinel
'

echo "Permission initialization passed for www-data UID $(id -u www-data)."
