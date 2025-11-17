#!/usr/bin/env bash
set -euo pipefail

# Render will mount your persistent disk at /data (we’ll map it in render.yaml).
# If DB_PATH not set, default to project dir fallback.
DB_PATH="${DB_PATH:-/data/db.sqlite}"
INIT_SQL="/var/www/html/ri-booking/init.sql"

# Ensure /data exists and is owned by www-data
mkdir -p "$(dirname "$DB_PATH")"
chown -R www-data:www-data "$(dirname "$DB_PATH")"

# Create DB if missing (idempotent)
if [ ! -f "$DB_PATH" ] && [ -f "$INIT_SQL" ]; then
  echo "Initializing SQLite at $DB_PATH"
  su -s /bin/sh -c "sqlite3 '$DB_PATH' < '$INIT_SQL'" www-data
fi

# Hand off to Apache (Render expects port 10000; Apache listens on 80 inside container,
# Render maps to 10000 automatically)
exec apache2-foreground
