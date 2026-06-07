#!/usr/bin/env bash
#
# Provision a throwaway WordPress (SQLite) with ComSign symlinked in, for tests.
#
# Usage:
#   tests/setup-wp.sh [TARGET_DIR]
#
# Prints the path to wp-load.php on the last line so callers can capture it:
#   WP_LOAD=$(tests/setup-wp.sh /tmp/comsign-wp | tail -1)
#
# Requires: bash, curl, unzip, php (with pdo_sqlite).
set -euo pipefail

TARGET="${1:-/tmp/comsign-wp}"
WP_VERSION="${WP_VERSION:-latest}"
SQLITE_VERSION="${SQLITE_VERSION:-2.1.13}"

# Resolve the plugin source (this repo) regardless of where we're invoked from.
PLUGIN_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Download with retries so a flaky connection to wordpress.org doesn't fail CI.
fetch() { # url outfile
	curl -fsSL \
		--retry 6 --retry-delay 5 --retry-all-errors --retry-connrefused \
		--connect-timeout 30 --max-time 300 \
		"$1" -o "$2"
}

mkdir -p "$TARGET"
cd "$TARGET"

if [ ! -f "wordpress/wp-load.php" ]; then
	echo "Downloading WordPress ($WP_VERSION)..." >&2
	fetch "https://wordpress.org/${WP_VERSION}.zip" wp.zip
	unzip -q wp.zip
	rm -f wp.zip
fi

WP="$TARGET/wordpress"
DBDIR="$TARGET/db"
mkdir -p "$DBDIR"

# SQLite integration plugin + db.php drop-in.
if [ ! -d "$WP/wp-content/plugins/sqlite-database-integration" ]; then
	echo "Downloading sqlite-database-integration ($SQLITE_VERSION)..." >&2
	# Try the plugin SVN/dist host first, then fall back to GitHub if it's down.
	fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_VERSION}.zip" sqlite.zip \
		|| fetch "https://github.com/WordPress/sqlite-database-integration/archive/refs/heads/main.zip" sqlite.zip
	unzip -q sqlite.zip -d "$WP/wp-content/plugins"
	# Normalise the folder name (GitHub archives unpack as <repo>-main).
	if [ ! -d "$WP/wp-content/plugins/sqlite-database-integration" ]; then
		mv "$WP/wp-content/plugins/sqlite-database-integration-main" "$WP/wp-content/plugins/sqlite-database-integration"
	fi
	rm -f sqlite.zip
fi
cp "$WP/wp-content/plugins/sqlite-database-integration/db.copy" "$WP/wp-content/db.php"
# The drop-in resolves the implementation via realpath(__DIR__/plugins/...), so
# no string replacement is needed.
sed -i "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP/wp-content/plugins/sqlite-database-integration#g" "$WP/wp-content/db.php" || true
sed -i "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$WP/wp-content/db.php" || true

# Symlink this plugin in.
rm -rf "$WP/wp-content/plugins/comsign"
ln -s "$PLUGIN_SRC" "$WP/wp-content/plugins/comsign"

# wp-config.php pointing at SQLite.
cat > "$WP/wp-config.php" <<PHP
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'DB_DIR', '$DBDIR/' );
define( 'DB_FILE', 'wp.sqlite' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'AUTH_KEY', 'test' ); define( 'SECURE_AUTH_KEY', 'test' ); define( 'LOGGED_IN_KEY', 'test' );
define( 'NONCE_KEY', 'test' ); define( 'AUTH_SALT', 'test' ); define( 'SECURE_AUTH_SALT', 'test' );
define( 'LOGGED_IN_SALT', 'test' ); define( 'NONCE_SALT', 'test' );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require_once ABSPATH . 'wp-settings.php';
PHP

# Install the site + activate the plugin (idempotent).
COMSIGN_WP_LOAD="$WP/wp-load.php" php "$PLUGIN_SRC/tests/install.php" >&2

echo "$WP/wp-load.php"
