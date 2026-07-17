#!/bin/sh
# Prepares configuration and the database, then hands off to supervisord.
# Runs on every boot and is safe to run repeatedly.
set -eu

SPOTWEB_HOME="${SPOTWEB_HOME:-/var/www/spotweb}"
CONFIG_DIR="/config"

# Defaults; set an empty value to disable a cron job.
SPOTWEB_CRON_RETRIEVE="${SPOTWEB_CRON_RETRIEVE-*/15 * * * *}"
SPOTWEB_CRON_CACHE_CHECK="${SPOTWEB_CRON_CACHE_CHECK-0 4 * * *}"

log() {
    echo "[entrypoint] $*"
}

# Apply the timezone to PHP as well as the shell.
if [ -n "${TZ:-}" ]; then
    printf 'date.timezone=%s\n' "$TZ" > /usr/local/etc/php/conf.d/timezone.ini
fi

# --- Database settings ------------------------------------------------------
# A file mounted at /config takes precedence; otherwise generate one from the
# SPOTWEB_DB_* environment variables. If neither is present, Spotweb falls back
# to the install.php wizard.
if [ -f "$CONFIG_DIR/dbsettings.inc.php" ]; then
    log "Using dbsettings.inc.php from $CONFIG_DIR"
    cp "$CONFIG_DIR/dbsettings.inc.php" "$SPOTWEB_HOME/dbsettings.inc.php"
elif [ -n "${SPOTWEB_DB_HOST:-}" ] && [ -n "${SPOTWEB_DB_NAME:-}" ]; then
    log "Generating dbsettings.inc.php from environment"
    {
        echo "<?php"
        echo "\$dbsettings = ["
        printf "    'engine' => '%s',\n" "${SPOTWEB_DB_TYPE:-pdo_mysql}"
        printf "    'host' => '%s',\n" "$SPOTWEB_DB_HOST"
        printf "    'dbname' => '%s',\n" "$SPOTWEB_DB_NAME"
        printf "    'user' => '%s',\n" "${SPOTWEB_DB_USER:-}"
        printf "    'pass' => '%s',\n" "${SPOTWEB_DB_PASS:-}"
        if [ -n "${SPOTWEB_DB_PORT:-}" ]; then
            printf "    'port' => '%s',\n" "$SPOTWEB_DB_PORT"
        fi
        echo "];"
    } > "$SPOTWEB_HOME/dbsettings.inc.php"
else
    log "No database configuration provided; use the install.php wizard"
fi

# Optional user setting overrides, persisted outside the image.
if [ -f "$CONFIG_DIR/ownsettings.php" ]; then
    log "Using ownsettings.php from $CONFIG_DIR"
    cp "$CONFIG_DIR/ownsettings.php" "$SPOTWEB_HOME/ownsettings.php"
fi

[ -f "$SPOTWEB_HOME/dbsettings.inc.php" ] && \
    chown www-data:www-data "$SPOTWEB_HOME/dbsettings.inc.php"
[ -f "$SPOTWEB_HOME/ownsettings.php" ] && \
    chown www-data:www-data "$SPOTWEB_HOME/ownsettings.php"

# --- Schema create / upgrade ------------------------------------------------
if [ -f "$SPOTWEB_HOME/dbsettings.inc.php" ]; then
    log "Waiting for the database to become available"
    attempt=0
    until php -r '
        require getenv("SPOTWEB_HOME") . "/dbsettings.inc.php";
        $driver = ($dbsettings["engine"] === "pdo_pgsql") ? "pgsql" : "mysql";
        $port = $dbsettings["port"] ?? ($driver === "pgsql" ? "5432" : "3306");
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", $driver, $dbsettings["host"], $port, $dbsettings["dbname"]);
        new PDO($dsn, $dbsettings["user"], $dbsettings["pass"], [PDO::ATTR_TIMEOUT => 3]);
    ' 2>/dev/null; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 60 ]; then
            log "Database still not reachable after 60 attempts; starting anyway"
            break
        fi
        sleep 2
    done

    log "Creating or upgrading the Spotweb database schema"
    if su-exec www-data:www-data php "$SPOTWEB_HOME/bin/upgrade-db.php"; then
        log "Schema is up to date"
    else
        log "upgrade-db.php returned non-zero; the web UI stays up for troubleshooting"
    fi
fi

# --- Cron jobs --------------------------------------------------------------
mkdir -p /etc/crontabs
CRON_FILE=/etc/crontabs/root
: > "$CRON_FILE"
if [ -n "$SPOTWEB_CRON_RETRIEVE" ]; then
    log "Scheduling spot retrieval: $SPOTWEB_CRON_RETRIEVE"
    echo "$SPOTWEB_CRON_RETRIEVE su-exec www-data php $SPOTWEB_HOME/retrieve.php >/proc/1/fd/1 2>/proc/1/fd/2" >> "$CRON_FILE"
fi
if [ -n "$SPOTWEB_CRON_CACHE_CHECK" ]; then
    log "Scheduling cache check: $SPOTWEB_CRON_CACHE_CHECK"
    echo "$SPOTWEB_CRON_CACHE_CHECK su-exec www-data php $SPOTWEB_HOME/bin/check-cache.php >/proc/1/fd/1 2>/proc/1/fd/2" >> "$CRON_FILE"
fi

log "Starting services"
exec "$@"
