#!/bin/bash
set -euo pipefail

mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/gopher
chown -R www-data:www-data /var/www/html/data /var/www/html/uploads /var/www/html/gopher
chmod 755 /var/www/html/data /var/www/html/uploads /var/www/html/gopher

apache2ctl -D FOREGROUND &
exec /usr/sbin/xinetd -dontfork -stayalive
