#!/bin/sh
set -e

# Ensure the config directory exists and is owned by www-data
mkdir -p /config
chown -R www-data:www-data /config
chmod 755 /config

exec "$@"
