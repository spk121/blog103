#!/bin/bash
set -euo pipefail

mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/gopher/releases
if [ ! -L /var/www/html/gopher/current ]; then
    mkdir -p /var/www/html/gopher/releases/empty
    ln -sfn releases/empty /var/www/html/gopher/current
fi
chown -R www-data:www-data /var/www/html/data /var/www/html/uploads /var/www/html/gopher
chmod 755 /var/www/html/data /var/www/html/uploads /var/www/html/gopher

apache2ctl -D FOREGROUND &
apache_pid=$!

/usr/sbin/xinetd -dontfork -stayalive &
xinetd_pid=$!

status=0
trap 'status=$?; kill "$apache_pid" "$xinetd_pid" 2>/dev/null || true; wait "$apache_pid" "$xinetd_pid" 2>/dev/null || true; exit "$status"' EXIT

wait -n "$apache_pid" "$xinetd_pid" || status=$?
kill "$apache_pid" "$xinetd_pid" 2>/dev/null || true
wait "$apache_pid" "$xinetd_pid" 2>/dev/null || true
exit "$status"
