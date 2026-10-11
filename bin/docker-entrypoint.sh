#!/bin/sh
# Amaya Log Intelligence — container entrypoint.
#
# Initialises MariaDB on first boot, generates config.php from environment
# variables, applies the schema, and creates the initial admin account when
# ADMIN_USERNAME / ADMIN_PASSWORD are supplied. Then hands off to supervisord.
set -e

DB_DIR=/var/lib/mysql
APP=/var/www/app

echo "[entrypoint] waiting for database initialisation…"
if [ ! -d "$DB_DIR/mysql" ]; then
  mkdir -p "$DB_DIR" /run/mysqld
  chown -R mysql:mysql "$DB_DIR" /run/mysqld
  mariadb-install-db --user=mysql --datadir="$DB_DIR" >/dev/null 2>&1 || true
fi

# Start a temporary mysqld to configure the app database.
mariadbd --user=mysql --datadir="$DB_DIR" --socket=/run/mysqld/mysqld.sock --skip-networking &
TMP_DB_PID=$!

i=0
while [ $i -lt 60 ]; do
  if mariadb-admin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then
    break
  fi
  i=$((i + 1))
  sleep 1
done

DB_NAME="${DB_NAME:-amaya}"
DB_USER="${DB_USER:-amaya}"
DB_PASS="${DB_PASS:-amaya}"

mariadb --socket=/run/mysqld/mysqld.sock <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL

kill "$TMP_DB_PID" 2>/dev/null || true
wait "$TMP_DB_PID" 2>/dev/null || true
sleep 1

# Generate runtime config from environment (never baked into the image).
echo "[entrypoint] writing public/config.php from environment"
cat > "$APP/public/config.php" <<PHP
<?php
return [
    'app' => [
        'name' => 'Amaya Log Intelligence',
        'env'  => 'production',
        'timezone' => 'Asia/Dhaka',
        'base_url' => '',
        'debug' => false,
    ],
    'db' => [
        'driver'  => 'mysql',
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int)(getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: '${DB_NAME}',
        'user'    => getenv('DB_USER') ?: '${DB_USER}',
        'pass'    => getenv('DB_PASS') ?: '${DB_PASS}',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name'       => 'amayali_sid',
        'session_lifetime'   => 7200,
        'cookie_secure'      => (getenv('COOKIE_SECURE') ?: 'true') === 'true',
        'cookie_samesite'    => 'Lax',
        'max_login_attempts' => 5,
        'lockout_seconds'    => 900,
        'password_min'       => 10,
    ],
    'google' => ['access_token' => getenv('GOOGLE_ACCESS_TOKEN') ?: ''],
];
PHP

# Apply schema and seed.
echo "[entrypoint] applying schema"
cd "$APP"
php bin/console.php migrate

# Create the bootstrap admin account if credentials were supplied.
if [ -n "${ADMIN_USERNAME:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
  echo "[entrypoint] ensuring admin account '${ADMIN_USERNAME}' exists"
  php bin/console.php user:create "${ADMIN_USERNAME}" admin --password="${ADMIN_PASSWORD}" || \
    echo "[entrypoint] account already exists — skipping"
fi

echo "[entrypoint] starting application"
exec "$@"