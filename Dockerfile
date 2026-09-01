FROM php:8.2-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        gophernicus \
        libsqlite3-dev \
        sqlite3 \
        xinetd \
    && printf '%s\n' \
        'service gopher' \
        '{' \
        '    socket_type = stream' \
        '    protocol = tcp' \
        '    wait = no' \
        '    user = _gophernicus' \
        '    bind = 0.0.0.0' \
        '    port = 70' \
        '    server = /usr/sbin/gophernicus' \
        '    server_args = -r /var/www/html/gopher/current' \
        '    disable = no' \
        '}' \
        > /etc/xinetd.d/gopher \
    && docker-php-ext-install pdo_sqlite \
    && apt-get purge -y --auto-remove libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Match config.php's MAX_UPLOAD_BYTES (32 MB); post_max_size is a little
# larger to leave room for the rest of the multipart form.
RUN printf '%s\n' \
        'upload_max_filesize = 32M' \
        'post_max_size = 40M' \
        > /usr/local/etc/php/conf.d/blog103-uploads.ini

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/gopher/releases/empty \
    && ln -sfn releases/empty /var/www/html/gopher/current \
    && chown -R www-data:www-data /var/www/html/data /var/www/html/uploads /var/www/html/gopher \
    && a2enmod rewrite \
    && find /var/www/html -type d -exec chmod 755 {} +

EXPOSE 80 70

RUN cat <<'EOF' > /usr/local/bin/docker-entrypoint.sh
#!/bin/bash
set -euo pipefail

mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/gopher/releases
if [ -e /var/www/html/gopher/current ] && [ ! -L /var/www/html/gopher/current ]; then
    echo "error: /var/www/html/gopher/current exists but is not a symlink" >&2
    exit 1
fi
if [ ! -e /var/www/html/gopher/current ]; then
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
EOF

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
