#!/bin/bash
# Azure Functions custom handler startup script.
# The Functions runtime sets FUNCTIONS_CUSTOMHANDLER_PORT; PHP listens on it
# and the runtime forwards every HTTP request to it.
set -euo pipefail

PORT="${FUNCTIONS_CUSTOMHANDLER_PORT:-8080}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "[azure-start] Root: $ROOT"
echo "[azure-start] Port: $PORT"

# ── 1. Install/update Composer dependencies if vendor/ is missing ─────────────
if [ ! -d "$ROOT/api/vendor" ]; then
    echo "[azure-start] Running composer install …"
    composer install --no-dev --no-interaction --prefer-dist \
        --working-dir="$ROOT/api" 2>&1
fi

# ── 2. Bootstrap cfg.php from cfg.azure.php if not already present ────────────
if [ ! -f "$ROOT/cfg.php" ]; then
    if [ -f "$ROOT/cfg.azure.php" ]; then
        echo "[azure-start] Linking cfg.azure.php → cfg.php"
        ln -sf "$ROOT/cfg.azure.php" "$ROOT/cfg.php"
    else
        echo "[azure-start] ERROR: neither cfg.php nor cfg.azure.php found" >&2
        exit 1
    fi
fi

# ── 3. Ensure writable storage directories ────────────────────────────────────
# Azure Functions provides /tmp as writable; all persistent blobs should be
# offloaded to Azure Blob Storage, but local temp paths are fine for PDF gen.
for DIR in \
    "${MYINVOICE_STORAGE_INVOICES:-/tmp/myinvoice/invoices}" \
    "${MYINVOICE_STORAGE_UPLOADS:-/tmp/myinvoice/uploads}" \
    "${MYINVOICE_STORAGE_BACKUP:-/tmp/myinvoice/backup}" \
    "${MYINVOICE_STORAGE_SESSIONS:-/tmp/myinvoice/sessions}" \
    "${MYINVOICE_STORAGE_CACHE:-/tmp/myinvoice/cache}" \
    "$ROOT/log"
do
    mkdir -p "$DIR"
done

# ── 4. Run pending database migrations ────────────────────────────────────────
echo "[azure-start] Running migrations …"
php "$ROOT/api/bin/migrate.php" || {
    echo "[azure-start] WARNING: migrations failed — continuing anyway" >&2
}

# ── 5. Start PHP built-in HTTP server ─────────────────────────────────────────
echo "[azure-start] Starting PHP on 0.0.0.0:${PORT} …"
exec php -S "0.0.0.0:${PORT}" "$ROOT/api/public/index.php"
