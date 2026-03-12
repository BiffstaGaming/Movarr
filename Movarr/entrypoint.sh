#!/bin/sh
set -e

# Ensure the config directory exists and is owned by www-data
mkdir -p /config
chown -R www-data:www-data /config
chmod 755 /config

# Apply timezone to PHP if TZ is set
if [ -n "$TZ" ]; then
    echo "date.timezone = $TZ" > /usr/local/etc/php/conf.d/timezone.ini
fi

# Start cron daemon in the background (handles all /etc/cron.d/* jobs)
cron

exec "$@"
